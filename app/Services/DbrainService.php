<?php

namespace App\Services\Dbrain;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Arr;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class DbrainService {
	const API_VERSION = 1;

	private static $log = null;

	public static function serviceAvailable() {
		return !empty(config('dbrain.DBRAIN_BASEURL'));
	}

	/**
	 * Асинхронный запрос на распознавание документа
	 *
	 * @param $imageDbrainUuid
	 * @param $imagePath
	 *
	 * @return mixed|null
	 */
	public static function recognize($imageDbrainUuid, $imagePath) {
		if (!self::serviceAvailable()) return null;

		$apiKey = config('dbrain.DBRAIN_API_KEY');
		$url = config('dbrain.DBRAIN_BASEURL');
		$endPoint = config('dbrain.DBRAIN_RECOGNIZE_END_POINT');
		if (!$imageDbrainUuid || !$imagePath || !$apiKey || !$url || !$endPoint) return null;

		$localFile = $imagePath;
		$removeLocal = false;

		try {
			if (starts_with($imagePath, 'http')) {
				$localFile = tempnam(sys_get_temp_dir(), 'dbr_');
				@copy($imagePath, $localFile);
				$removeLocal = true;
			}

			$result = (new Client())->post($url . $endPoint . '?token=' . $apiKey . '&async=true', [
				'verify' => false,
				'headers' => [
					'accept' => 'application/json',
				],
				'multipart' => [
					[
						'name' => 'image',
						'contents' => Utils::tryFopen($localFile, 'r'),
					]
				],
			]);
			$httpCode = $result->getStatusCode();
			$response = $result->getBody()->getContents();

			self::logInfo(__METHOD__. ' RESPONSE', ['code' => $httpCode, 'response' => $response]);
			$response = json_decode($response, true);

			if ($removeLocal) @unlink($localFile);

			return ($httpCode == 200) ? Arr::get($response, 'task_id') : null;

		} catch (\Exception $e) {
			self::logError('Cannot send request at ' . __METHOD__ . ': ' . $e->getMessage(), ['imageDbrainUuid' => $imageDbrainUuid]);
			if ($removeLocal) @unlink($localFile);
		}

		return null;
	}

	/**
	 * Получение ответа на ранее отправленный запрос на распознавание документа
	 *
	 * @param $taskId
	 *
	 * @return array|null
	 */
	public static function getResult($taskId) {
		if (!self::serviceAvailable()) return null;

		$apiKey = config('dbrain.DBRAIN_API_KEY');
		$url = config('dbrain.DBRAIN_BASEURL');
		$endPoint = config('dbrain.DBRAIN_RESULT_END_POINT');
		if (!$taskId || !$apiKey || !$url || !$endPoint) return null;

		try {
			$result = (new Client())->get($url . $endPoint . '/' . $taskId . '?token=' . $apiKey, [
				'verify' => false,
				'headers' => [
					'accept' => 'application/json',
					'content-type' => 'application/json',
				],
			]);
			$httpCode = $result->getStatusCode();
			if (!in_array($httpCode, [200, 202])) return null;

			$response = $result->getBody()->getContents();

			self::logInfo(__METHOD__ . ' RESPONSE', ['code' => $httpCode, 'taskId' => $taskId, 'response' => $response]);
			$response = json_decode($response, true);

			// распознавание еще не завершено
			if ($httpCode == 202) {
				return [
					'error' => array_key_exists('message', $response) ? $response['message'] : '',
				];
			}

			return $response;

		} catch (\Exception $e) {
			self::logError('Cannot send request at ' . __METHOD__ . ': ' . $e->getMessage(), ['taskId' => $taskId]);
		}

		return null;
	}

	protected static function getLogger() {
		if (!self::$log) {
			self::$log = new Logger('dbrain');
			try {
				self::$log->pushHandler(new StreamHandler(storage_path('logs/' . config('dbrain.DBRAIN_LOG')), Logger::DEBUG));
			} catch (\Exception $e) {
				\Log::critical('Cannot create log file for dbrain: ' . $e->getMessage());
				self::$log = \Log::getMonolog();
			}
		}
		return self::$log;
	}

	protected static function logInfo($msg, $context = []) {
		self::getLogger()->info($msg, $context);
	}

	protected static function logError($msg, $context = []) {
		self::getLogger()->error($msg, $context);
	}
}
