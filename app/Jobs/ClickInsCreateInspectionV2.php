<?php

namespace App\Jobs;

use App\DlClassEnum;
use App\Image;
use App\Inspection;
use App\Jobs\QueueExtension\ReleaseHelperTrait;
use App\Services\ClickIns\ClickInsServiceV2;
use App\Services\Locker;
use App\Services\Semaphore;
use GuzzleHttp\Psr7\MimeType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ClickInsCreateInspectionV2 extends Job implements ShouldQueue {
	use InteractsWithQueue, SerializesModels, ReleaseHelperTrait;

	protected $inspectionId;

	public function __construct($inspection) {
		$this->inspectionId = ($inspection instanceof Inspection) ? $inspection->id : intval($inspection);
	}

	/**
	 * @return int|void
	 */
	public function handle() {
		if (!ClickInsServiceV2::serviceAvailable()) return;

		set_time_limit(650);

		$semaphore = Semaphore::getSemaphoreOrNull('ClickInsCreateInspectionV2', 1, 0, 200); // 1 слот для одновременной обработки, 0 секунд ждём свою очередь, 200 секунд даём на обработку
		if (!$semaphore) {
			if ($this->attempts() >= 5) {
				\Log::error("Can't execute ClickInsCreateInspectionV2 for {$this->inspectionId} -- no semaphore slots left at least 5 times in a row, RELEASED ONCE MORE as a clean copy");
				return $this->releaseCleanAttempt(20);
			} else {
				return $this->releaseAgain($this->attempts() * 10);
			}
		}

		// дополнительно ещё смотрим, чтобы по одному и тому же осмотру не посылали лишнего в несколько потоков
		$lock = Locker::getLockOrNull('ClickInsCreateInspectionV2_insp_'.$this->inspectionId, 1, 600); // 1 секунду ждём лок, 600 секунд даём на обработку
		if (!$lock) {
			// пробуем повторить обработку до посинения
			return $this->releaseCleanAttempt(5);
		}

		$inspection = \App\Inspection::find($this->inspectionId);
		if (!$inspection) return;

		// главная проверка на то, нужно ли вообще посылать какие-то запросы в ClickIns
		if (!$inspection->hasDlClassConnected(DlClassEnum::CLICKINS)) return;

		$processes = $inspection->processes->sortByDesc('created_at');
		if (!$processes || $processes->isEmpty()) return;

		if (!$inspection->clickins_uuid) {
			$inspection->setClickinsUUID();
		}
		
		// clickins_case_id определяем один раз
		if (!$inspection->clickins_case_id) {
			$inspectionCaseId = ClickInsServiceV2::createInspection($inspection->clickins_uuid);
			if (!$inspectionCaseId) {
				// Пробуем повторить
				\Log::error("Can't do ClickInsServiceV2::createInspection() for {$this->inspectionId}, trying to release again. Current attempt: " . $this->attempts());
				return $this->releaseIfWasLessAttempts(3, 15);
			}
			$inspection->clickins_case_id = $inspectionCaseId;
			$inspection->save();
		} elseif (!ClickInsServiceV2::allowInspectionCaseProceed($inspection->clickins_case_id)) {
			// если уже был открытый кейс, тогда делаем проверку, можно ли ещё посылать в него файлы
			return; // если нет, то идём лесом с нашими новыми файлами
		}

		$clickinsResult = $inspection->clickins_result_json
			? json_decode($inspection->clickins_result_json, true)
			: ['api_version' => ClickInsServiceV2::API_VERSION]
		;

		// Если для этого осмотра уже использовался ClickIns, но старой версии, то
		// сохраним старые данные для истории, но использовать их уже не будем
		if ($clickinsResult && Arr::get($clickinsResult, 'api_version') != ClickInsServiceV2::API_VERSION) {
			$clickinsResult = [
				'api_version' => ClickInsServiceV2::API_VERSION,
				'old_result' => $clickinsResult,
			];
		}

		$images = [];
		/** @var \App\Process[] $processes */
		foreach ($processes as $process) {
			foreach ($process->getSteps() as $step) {
				if ($step->step_type != 'photo') continue;

				if (!$step->hasDlClassConnected(DlClassEnum::CLICKINS)) continue;

				foreach ($process->getPhotos($step) as $image) {
					if (!$image || !$image->exists) continue;

					// Панорамы на разметку не отправляем
					if ($image->resource == Image::RESOURCE_CLIENT_APP_PANORAMA) {
						continue;
					}

					// VR-166 -- если ответ по фотке ранее уже был, не отправляем повторно на обработку
					if ($image->clickins_uuid && array_key_exists($image->clickins_uuid, $inspection->getDlActualImageData(DlClassEnum::CLICKINS))) {
						continue;
					}

					$imagePath = $image->getFilename();
					if (!starts_with($imagePath, 'http')) {
						$imagePath = public_path($imagePath);
					}

					if (starts_with($imagePath, 'http') || file_exists($imagePath)) {
						$imageContent = @file_get_contents($imagePath);
						if (mb_strlen($imageContent) < 10 * 1024) {
							$imageContent = false; // картинки меньше 10Кб нет смысла отправлять на проверку
						}
					} else {
						$imageContent = false;
					}

					if (!$imageContent) continue;

					list ($imageWidth, $imageHeight) = ($image->width <= 0) || ($image->height <= 0)
						? (getimagesize($imagePath) ?? [0, 0])
						: [$image->width, $image->height]
					;

					if ($imageWidth <= 50 || $imageHeight <= 50) {
						continue; // совсем маленькие изображения тоже нет смысла отправлять на проверку
					}

					if (!$image->clickins_uuid) {
						$image->setClickinsUUID();
						$image->save();
					}

					// @TODO: очень похоже, что это нужно в сервис уносить, но при данной структуре кода в сервисе это тоже смотрится ни к месту
					$images[] = [
						'image_id' => $image->clickins_uuid,
						'name' => $image->clickins_uuid . '.' . $image->ext,
						'content_type' => MimeType::fromExtension($image->ext),
						'content' => base64_encode($imageContent),
						'context' => [
							'view_type' => 'FULL_FRAME',
						],
						'resolution' => [
							'width' => $imageWidth,
							'height' => $imageHeight,
						],
					];

					$clickinsResult['requests'][$inspection->clickins_case_id][$image->clickins_uuid] = date('Y-m-d H:i:s');
				}
			}
		}

		if ($images) {
			$response = ClickInsServiceV2::uploadImages($inspection->clickins_case_id, $images);
			$clickinsResult['responses'][$inspection->clickins_case_id]['images'] = $response;
		} else {
			$response = null;
		}

		$inspection->clickins_result_json = json_encode($clickinsResult, JSON_UNESCAPED_UNICODE);
		$inspection->save();
	}
}
