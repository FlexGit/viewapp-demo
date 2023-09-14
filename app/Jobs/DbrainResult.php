<?php

namespace App\Jobs;

use App\Inspection;
use App\Jobs\QueueExtension\ReleaseHelperTrait;
use App\Services\Dbrain\DbrainService;
use App\Services\Locker;
use App\Services\Semaphore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DbrainResult extends Job implements ShouldQueue {
	use InteractsWithQueue, SerializesModels, ReleaseHelperTrait;

	protected $inspectionId;
	protected $taskId;

	public function __construct($inspection, $taskId) {
		$this->inspectionId = ($inspection instanceof Inspection) ? $inspection->id : intval($inspection);
		$this->taskId = $taskId;
	}

	/**
	 * @return int|void
	 */
	public function handle() {
		if (!DbrainService::serviceAvailable()) return;

		set_time_limit(650);

		$semaphore = Semaphore::getSemaphoreOrNull('Dbrain', 1, 0, 200); // 1 слот для одновременной обработки, 0 секунд ждём свою очередь, 200 секунд даём на обработку
		if (!$semaphore) {
			if ($this->attempts() >= 5) {
				\Log::error("Can't execute DbrainResult for inspection {$this->inspectionId}, task {$this->taskId} -- no semaphore slots left at least 5 times in a row, RELEASED ONCE MORE as a clean copy");
				return $this->releaseCleanAttempt(20);
			} else {
				return $this->releaseAgain($this->attempts() * 10);
			}
		}

		$result = DbrainService::getResult($this->taskId);

		// @TODO: подумать, как корректно обрабатывать ошибки по типу "License is not valid" и т.п., пока пропускаем
		if (is_null($result)) {
			// task_id не найден
			\Log::error("Can't do DbrainService::getResult() for inspection {$this->inspectionId}, task {$this->taskId} -- no result");
			return;
		}

		if (array_key_exists('error', $result)) {
			// распознавание еще не завершено, пробуем повторить
			\Log::info("Can't do DbrainService::getResult() for inspection {$this->inspectionId}, task {$this->taskId} -- result has error. Trying to release again");
			return $this->releaseCleanAttempt(20);
		}

		// сохраняем результат обработки в осмотр через блокировку, чтобы не затереть какие-либо другие данные

		$lock = Locker::getLockOrNull('DbrainResultJson_insp_' . $this->inspectionId, 10, 20); // 10 секунд ждём лок, 20 секунд даём на обработку
		if (!$lock) return $this->releaseCleanAttempt(5);

		$inspection = Inspection::find($this->inspectionId);
		if (!$inspection) return;

		$dbrainResult = json_decode($inspection->dbrain_result_json, true);
		if (empty($dbrainResult)) {
			$dbrainResult = ['api_version' => DbrainService::API_VERSION];
		}

		foreach ($dbrainResult['responses'][$this->taskId]['images'] ?? [] as $dbrainImageUuid => $dbrainImage) {
			$dbrainResult['responses'][$this->taskId]['images'][$dbrainImageUuid] = $result;
		}

		// Надо проверить, что на каждый реквест уже получен ответ, если это так, то можно отправить вебхук,
		// если он ещё не был отправлен
		$webhookSent = $dbrainResult['end_of_process_webhook_sent'] ?? false;
		if (!$webhookSent) {
			$finished = true;
			foreach ($dbrainResult['requests'] as $dbrainImageUuid => $requestMoment) {
				if (empty(array_filter(data_get($dbrainResult, 'responses.*.images.' . $dbrainImageUuid)))) {
					$finished = false;
					break;
				}
			}

			if ($finished) {
				dispatch(new SendDbrainWebhook($inspection));
			}
		}

		$inspection->dbrain_result_json = json_encode($dbrainResult, JSON_UNESCAPED_UNICODE);
		if (!$inspection->save()) return $this->releaseCleanAttempt(5);
	}
}
