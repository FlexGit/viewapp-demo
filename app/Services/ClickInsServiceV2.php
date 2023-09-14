<?php

namespace App\Services\ClickIns;

use App\Inspection;
use App\Services\FileUploadHelper;
use App\Services\HelpFunctions;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class ClickInsServiceV2 {
	const UPLOAD_PATH = '/upload/clickins/v2/';
	const API_VERSION = 2;
	const SERVICE_FEATURES = [
		'VEHICLE_MODEL_CHECK',
		'DAMAGE_MEASUREMENT',
		'PANEL_DISTANCE_MEASUREMENT',
		'RUN_DAMAGE_DETECTION_ON_UPLOAD',
		'GENERATE_DAMAGE_OVERLAY',
	];
	const HEADERS = [
		'accept' => 'application/json',
		'content-type' => 'application/json',
	];

	private static $log = null;

	public static function serviceAvailable() {
		return (bool)config('clickins.USE_CLICKINS');
	}

	public static function createInspection($inspectionClickinsUuid) {
		if (!self::serviceAvailable()) return null;

		$apiKey = config('clickins.CLICKINS_V2_API_KEY');
		$url = config('clickins.CLICKINS_V2_BASEURL');
		if (!$inspectionClickinsUuid || !$apiKey || !$url) return null;

		$data = [
			// Пока у нас каждый осмотр в отдельном процессе, поэтому эти идентификаторы одинаковые
			'client_process_id' => $inspectionClickinsUuid,
			'client_token' => $inspectionClickinsUuid,
			'features' => self::SERVICE_FEATURES,
		];

		if (!self::logRequest($data)) {
			return null;
		}

		try {
			$result = (new Client())->post($url . '/inspections?key=' . $apiKey . '&upload_type=multipart', [
				'verify' => false,
				'headers' => self::HEADERS,
				'json' => $data,
			]);
			$httpCode = $result->getStatusCode();
			$response = (string)$result->getBody()->getContents();
			self::logInfo(__FUNCTION__ . ' ' . __CLASS__ . ' RESPONSE', ['code' => $httpCode, 'response' => $response]);
			$response = json_decode($response, true);

			return ($httpCode == 200) ? Arr::get($response, 'inspection_case_id') : null;
		} catch (\Exception $e) {
			self::logError('Cannot send ' . __FUNCTION__ . ' ' . __CLASS__ . ' request: ' . $e->getMessage(), ['DocumentsBatchId' => $data['DocumentsBatchId'] ?? '-']);
		}

		return null;
	}

	public static function uploadImages($inspectionClickinsCaseId, $images) {
		$apiKey = config('clickins.CLICKINS_V2_API_KEY');
		$url = config('clickins.CLICKINS_V2_BASEURL');
		if (!$inspectionClickinsCaseId || !$images || !$apiKey || !$url) return [];

		$data = [
			'images' => $images,
		];

		$dataForLog = [];
		foreach (($data['images'] ?? []) as $dImage) {
			$dImage['content'] = '...';
			$dataForLog[] = $dImage;
		}

		if (!self::logRequest($dataForLog)) {
			return [];
		}

		try {
			$result = (new Client())->post($url . '/inspections/' . $inspectionClickinsCaseId . '/images/?key=' . $apiKey . '&upload_type=multipart', [
				'verify' => false,
				'headers' => self::HEADERS,
				'json' => $data,
			]);
			$httpCode = $result->getStatusCode();
			$response = (string)$result->getBody()->getContents();

			$respLogFile = 'cb-v2-' . time() . '-' . date('Y-m-d--H-i-s') . '-' . rand(0, 1000000);
			@file_put_contents(storage_path('logs/clickins/' . $respLogFile), $response);
			self::logInfo(__FUNCTION__ . ' ' . __CLASS__ . ' RESPONSE', ['code' => $httpCode, 'logfile' => $respLogFile]);

			$response = json_decode($response, true);

			$uploadedImages = [];
			foreach ($response['images'] ?? [] as $image) {
				$imageClickinsUuid = $image['image_id'] ?? '';
				if (!$imageClickinsUuid) continue;
				
				$damageOverlay = $image['damage_overlay'] ?? '';
				$uploadedImages[$imageClickinsUuid] = [];
				
				$uploadedImages[$imageClickinsUuid]['damage_overlay'] = $damageOverlay ? (self::uploadFromBase64($damageOverlay, 'jpg') ?: '') : '';
				
				if ($image['image_quality_result'] ?? null) {
					$uploadedImages[$imageClickinsUuid]['image_quality'] = $image['image_quality_result'];
				}

				// Обнаруженные повреждения
				foreach ($image['detected_damages'] ?? [] as $damage) {
					$damageId = $damage['damage_id'] ?? null;
					if (!$damageId) {
						continue;
					}

					$uploadedImages[$imageClickinsUuid]['detected_damages'][$damageId] = [
						'damage_type' => Arr::get($damage, 'damage_type'),
						'bounding_box' => Arr::get($damage, 'segmentation.bounding_box', []),
						'score' => Arr::get($damage, 'segmentation.score'),
						'contours' => Arr::get($damage, 'segmentation.contours'),
					];

					foreach ($damage['vehicle_parts'] ?? [] as $part) {
						$partId = $part['part_id'] ?? null;
						if (!$partId) {
							continue;
						}

						$uploadedImages[$imageClickinsUuid]['detected_damages'][$damageId]['parts'][$partId] = [
							'part_name' => Arr::get($part, 'part_name'),
							'part_description' => Arr::get($part, 'part_description'),
						];
					}
				}
			}

			return ($httpCode == 200 && $uploadedImages) ? $uploadedImages : [];
		} catch (\Exception $e) {
			self::logError('Cannot send ' . __FUNCTION__ . ' ' . __CLASS__ . ' request: ' . $e->getMessage(), ['inspectionCaseId' => $inspectionClickinsCaseId ?? '-']);
		}

		return [];
	}

	public static function createAsyncProcess($inspectionCaseId) {
		if (!self::serviceAvailable()) return false;

		$apiKey = config('clickins.CLICKINS_V2_API_KEY');
		$url = config('clickins.CLICKINS_V2_BASEURL');
		$callbackUrl = asset(config('clickins.CLICKINS_V2_CALLBACK_URL'));
		if (!$inspectionCaseId || !$apiKey || !$url || !$callbackUrl) return false;

		try {
			$result = (new Client())->post($url . '/inspections/' . $inspectionCaseId . '/asyncProcess/?key=' . $apiKey . '&callback=' . $callbackUrl, [
				'verify' => false,
				'headers' => self::HEADERS,
				'json' => (object)[],
			]);
			$httpCode = $result->getStatusCode();
			$response = (string)$result->getBody()->getContents();

			self::logInfo(__FUNCTION__ . ' ' . __CLASS__ . ' RESPONSE', ['code' => $httpCode, 'response' => $response]);

			return in_array($httpCode, [200, 204]);
		} catch (\Exception $e) {
			self::logError('Cannot send ' . __FUNCTION__ . ' ' . __CLASS__ . ' request: ' . $e->getMessage(), ['inspectionCaseId' => $inspectionCaseId]);
		}

		return false;
	}

	public static function asyncProcessCallback() {
		$inspectionResponse = file_get_contents("php://input");
		$logFile = 'cb-v2-' . time() . '-' . date('Y-m-d--H-i-s');
		@file_put_contents(storage_path('logs/clickins/' . $logFile), $inspectionResponse);
		if (!$inspectionResponse) return false;

		$inspectionResponse = json_decode($inspectionResponse, true);
		$clickinsCaseId = Arr::get($inspectionResponse, 'inspection_case.inspection_case_id');
		$damageRecognition = Arr::get($inspectionResponse, 'damage_recognition');
		if (!$clickinsCaseId || !$damageRecognition) return false;

		$inspection = Inspection::where('clickins_case_id', $clickinsCaseId)->first();
		if (!$inspection) return false;

		$clickinsResult = $inspection->clickins_result_json ? json_decode($inspection->clickins_result_json, true) : [];
		if (empty($clickinsResult['responses'][$inspection->clickins_case_id])) return false;

		$clickinsResult['responses'][$inspection->clickins_case_id]['vehicle_model_detection'] = Arr::get($inspectionResponse, 'vehicle_model_detection', []);
		$clickinsResult['responses'][$inspection->clickins_case_id]['alerts'] = Arr::get($inspectionResponse, 'alerts', []);

		// "Разложим" распознанные повреждения по повреждениям, первично обнаруженным на этапе загрузки изображений
		if ($clickinsResult['responses'][$inspection->clickins_case_id]['images'] ?? []) {
			foreach ($damageRecognition as $damage) {
				$recognizedDamageId = Arr::get($damage, 'damage_id');
				if (!$recognizedDamageId) {
					continue;
				}

				$recognizedDamage = [
					'recognized_damage_id' => $recognizedDamageId,
					'damage_area_codes' => Arr::get($damage, 'damage_area_codes', []),
					'damage_type' => Arr::get($damage, 'damage_type'),
					'damage_severity' => Arr::get($damage, 'damage_severity'),
					'damage_min_enclosing_box' => Arr::get($damage, 'damage_min_enclosing_box'),
					'minimum_distance_to_panels' => Arr::get($damage, 'minimum_distance_to_panels'),
					'damage_area' => Arr::get($damage, 'damage_area'),
					'side' => Arr::get($damage, 'side'),
					'part' => Arr::get($damage, 'part'),
				];

				// В "referenced_damage_ids" лежат идентификаторы повреждений, которые были найдены на первом этапе (при первичной загрузке изображений)
				foreach (Arr::get($damage, 'referenced_damage_ids', []) as $referencedDamageId) {
					$damagedImageFound = false;
					foreach ($clickinsResult['responses'][$inspection->clickins_case_id]['images'] as $imageClickinsUuid => $image) {
						if (!isset($image['detected_damages'][$referencedDamageId])) continue;
						
						$damagedImageFound = true;
						$clickinsResult['responses'][$inspection->clickins_case_id]['images'][$imageClickinsUuid]['detected_damages'][$referencedDamageId]['recognized_damages'][$recognizedDamageId] = $recognizedDamage;
					}
					if (!$damagedImageFound) {
						self::logError("asyncProcessCallback() damage {$referencedDamageId} not found for case {$inspection->clickins_case_id}. see log in $logFile");
					}
				}
			}
		}

		try {
			$inspection->clickins_result_json = json_encode($clickinsResult, JSON_UNESCAPED_UNICODE);
			return $inspection->save();
		} catch (\Exception $e) {
			self::logError('Cannot save ' . __FUNCTION__ . ' ' . __CLASS__ . ': ' . $e->getMessage(), ['inspectionResponse' => $inspectionResponse]);
		}

		return false;
	}

	public static function allowInspectionCaseProceed($inspectionCaseId) {
		if (!self::serviceAvailable()) return false;

		$apiKey = config('clickins.CLICKINS_V2_API_KEY');
		$url = config('clickins.CLICKINS_V2_BASEURL');
		$callbackUrl = asset(config('clickins.CLICKINS_V2_CALLBACK_URL'));
		$timeLimit = config('clickins.CLICKINS_V2_INSPECTION_CASE_TIME_LIMIT');
		if (!$inspectionCaseId || !$apiKey || !$url || !$callbackUrl || !$timeLimit) return false;
		
		try {
			$result = (new Client())->get($url . '/inspections/' . $inspectionCaseId . '/?key=' . $apiKey, [
				'verify' => false,
				'headers' => self::HEADERS,
				'json' => (object)[],
			]);
			$httpCode = $result->getStatusCode();
			$response = (string)$result->getBody()->getContents();
			
			self::logInfo(__FUNCTION__ . ' ' . __CLASS__ . ' RESPONSE', ['code' => $httpCode, 'response' => $response]);
			
			$response = json_decode($response, true);
			
			if ($httpCode != 200) return false;

			if (in_array(Arr::get($response, 'status'), ['COMPLETED', 'FAILED'])) return false;
			
			$createdOn = Arr::get($response, 'created_on');
			if (!$createdOn) return false;
			if (HelpFunctions::convertAnyToTimestamp() > HelpFunctions::convertAnyToTimestamp($createdOn) + $timeLimit) return false;

			return true;
		} catch (\Exception $e) {
			self::logError('Cannot send ' . __FUNCTION__ . ' ' . __CLASS__ . ' request: ' . $e->getMessage(), ['inspectionCaseId' => $inspectionCaseId]);
		}
		
		return false;
	}
	
	/**
	 * @param string $content
	 * @param string $ext
	 * @return string
	 */
	public static function uploadFromBase64($content, $ext) {
		$filename = FileUploadHelper::uploadFromBase64($content, public_path(self::UPLOAD_PATH), $ext);
		if (false === $filename) {
			$e = error_get_last();
			$msg = $e['message'] ?? 'no description';
			self::logError('Cannot save ' . __FUNCTION__ . ' ' . __CLASS__ . ': ' . $msg);

			return null;
		}

		return $filename;
	}

	protected static function getLogger() {
		if (!self::$log) {
			self::$log = new Logger('clickins');
			try {
				self::$log->pushHandler(new StreamHandler(storage_path('logs/' . config('clickins.CLICKINS_V2_LOG')), Logger::DEBUG));
			} catch (\Exception $e) {
				\Log::critical('Cannot create log file for ' . __FUNCTION__ . ' ' . __CLASS__, [$e->getMessage()]);
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

	protected static function logRequest($data) {
		try {
			self::logInfo(__FUNCTION__ . ' ' . __CLASS__ . ' REQUEST', is_array($data) ? $data : [json_encode($data, JSON_UNESCAPED_UNICODE)]);
		} catch (\Exception $e) {
			self::logError('Cannot write log for ' . __FUNCTION__ . ' ' . __CLASS__ . ' request: ' . $e->getMessage());
			self::logInfo('(*) ' . __FUNCTION__ . ' ' . __CLASS__ . ' REQUEST', is_array($data) ? $data : [json_encode($data, JSON_UNESCAPED_UNICODE)]);

			return false;
		}

		return true;
	}
}
