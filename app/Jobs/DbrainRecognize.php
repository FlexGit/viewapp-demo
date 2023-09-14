<?php

namespace App\Jobs;

use App\DlClassEnum;
use App\Image;
use App\Inspection;
use App\Jobs\QueueExtension\ReleaseHelperTrait;
use App\Services\Dbrain\DbrainService;
use App\Services\Locker;
use App\Services\Semaphore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DbrainRecognize extends Job implements ShouldQueue {
	use InteractsWithQueue, SerializesModels, ReleaseHelperTrait;

	protected $inspectionId;

	public function __construct($inspection) {
		$this->inspectionId = ($inspection instanceof Inspection) ? $inspection->id : intval($inspection);
	}

	/**
	 * @return int|void
	 */
	public function handle() {
		if (!DbrainService::serviceAvailable()) return;

		set_time_limit(650);

		$semaphore = Semaphore::getSemaphoreOrNull('Dbrain', 1, 0, 300); // 1 слот для одновременной обработки, 0 секунд ждём свою очередь, 300 секунд даём на обработку
		if (!$semaphore) {
			if ($this->attempts() >= 5) {
				\Log::error("Can't execute DbrainRecognize for {$this->inspectionId} -- no semaphore slots left at least 5 times in a row, RELEASED ONCE MORE as a clean copy");
				return $this->releaseCleanAttempt(20);
			} else {
				return $this->releaseAgain($this->attempts() * 10);
			}
		}

		// блокировка для правильной работы $image->setDbrainUUID();
		$lock = Locker::getLockOrNull('DbrainResultJson_insp_' . $this->inspectionId, 1, 600); // 1 секунду ждём лок, 600 секунд даём на обработку
		if (!$lock) {
			// пробуем повторить обработку до посинения
			return $this->releaseCleanAttempt(5);
		}

		$inspection = Inspection::find($this->inspectionId);
		if (!$inspection) return;

		// главная проверка на то, нужно ли вообще посылать какие-то запросы в Dbrain
		if (!$inspection->hasDlClassConnected(DlClassEnum::DBRAIN)) return;

		// информация об актуальном ответе Dbrain по фоткам осмотра
		$actualImageData = $inspection->getDbrainActualImageData();

		$processes = $inspection->processes->sortByDesc('created_at');
		if (!$processes || $processes->isEmpty()) return;

		$images = [];
		/** @var \App\Process[] $processes */
		foreach ($processes as $process) {
			foreach ($process->getSteps() as $step) {
				if ($step->step_type != 'photo') continue;

				if (!$step->hasDlClassConnected(DlClassEnum::DBRAIN)) continue;

				foreach ($process->getPhotos($step) as $image) {
					if (!$image || !$image->exists) continue;

					// Панорамы на разметку не отправляем
					if ($image->resource == Image::RESOURCE_CLIENT_APP_PANORAMA) {
						continue;
					}

					if (!$image->dbrain_uuid) {
						$image->setDbrainUUID();
						if (!$image->save()) return $this->releaseCleanAttempt(5);
					}

					// Если ответ по фотке ранее уже был, не отправляем повторно на обработку
					if (array_key_exists($image->dbrain_uuid, $actualImageData)) {
						continue;
					}

					$imagePath = $image->getFilename();
					if (!starts_with($imagePath, 'http')) {
						$imagePath = public_path($imagePath);
					}

					$images[$image->dbrain_uuid] = $imagePath;
				}
			}
		}
		if (!$images) return;

		$dbrainResult = json_decode($inspection->dbrain_result_json, true);
		if ($dbrainResult && $dbrainResult['end_of_process_webhook_sent'] ?? false) {
			unset($dbrainResult['end_of_process_webhook_sent']);
			$inspection->dbrain_result_json = json_encode($dbrainResult, JSON_UNESCAPED_UNICODE);
			if (!$inspection->save()) return $this->releaseCleanAttempt(5);
		}

		$webhookTimeout = min(
			count($images) * intval(config('dbrain.DBRAIN_WEBHOOK_PHOTO_TIMEOUT', 3)),
			intval(config('dbrain.DBRAIN_WEBHOOK_TOTAL_TIMEOUT', 10))
		);

		$webhookJob = new SendDbrainWebhook($inspection);
		$webhookJob->delay($webhookTimeout);
		dispatch($webhookJob);

		// отпускаем блокировку осмотра
		unset($lock);

		foreach ($images as $dbrainImageUuid => $imagePath) {
			$requestMoment = date('Y-m-d H:i:s');
			$taskId = DbrainService::recognize($dbrainImageUuid, $imagePath);
			if (!$taskId) {
				// Пробуем повторить
				\Log::error("Can't do DbrainService::recognize() for image {$dbrainImageUuid}, trying to release again");
				return $this->releaseCleanAttempt(5);
			}

			// сохраняем данные о запросе в осмотре через блокировку, чтобы не затереть какие-либо другие результаты

			$lock = Locker::getLockOrNull('DbrainResultJson_insp_' . $this->inspectionId, 10, 20); // 10 секунд ждём лок, 20 секунд даём на обработку
			if (!$lock) return $this->releaseCleanAttempt(5);

			$inspection = $inspection->fresh();

			$dbrainResult = json_decode($inspection->dbrain_result_json, true);
			if (empty($dbrainResult)) {
				$dbrainResult = ['api_version' => DbrainService::API_VERSION];
			}
			$dbrainResult['requests'][$dbrainImageUuid] = $requestMoment;
			$dbrainResult['responses'][$taskId]['images'][$dbrainImageUuid] = [];

			$inspection->dbrain_result_json = json_encode($dbrainResult, JSON_UNESCAPED_UNICODE);
			if (!$inspection->save()) return $this->releaseCleanAttempt(5);

			unset($lock);

			// запускаем асинхронное получение результатов запроса

			dispatch(new DbrainResult($inspection, $taskId));
		}
	}
}
