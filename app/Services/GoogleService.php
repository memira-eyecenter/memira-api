<?php

namespace App\Services;

use App\Utilities\RegularHours;
use App\Utilities\SpecialHours;
use Google\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class GoogleService {
	protected $client;
	protected $accessToken;

	public function __construct() {
		$this->client = new Client();
		$this->client->setAuthConfig(config('services.google.credentials_path'));
		$this->client->addScope('https://www.googleapis.com/auth/business.manage');
		$this->client->useApplicationDefaultCredentials();

		$this->accessToken = $this->client->fetchAccessTokenWithAssertion()['access_token'];
	}

	public function getAccounts() {
		$response = Http::withToken($this->accessToken)
			->get("https://mybusinessaccountmanagement.googleapis.com/v1/accounts");

		return $response->json('accounts');
	}

	public function getAccountId($accountName = null) {
		if (getenv('GOOGLE_BUSINESS_ACCOUNT_ID')) {
			return getenv('GOOGLE_BUSINESS_ACCOUNT_ID');
		}

		$account = collect($this->getAccounts())->firstWhere('accountName', $accountName);

		$accountId = collect($account)->get('name');

		if ($accountId) {
			return explode('/', $accountId)[1] ?? null;
		}

		return null;
	}

	public function getLocations() {
		$accountId = $this->getAccountId();

		// Cache::forget('google/locations');

		$locations = Cache::remember("google/locations", 300, function () use ($accountId) {
			$readMask = [
				'name',
				'title',
				'storeCode',
				'regularHours',
				'specialHours',
				'storefrontAddress',
				'metadata.placeId',
				'metadata.mapsUri',
				'metadata.duplicateLocation',
				'openInfo.status',
			];

			$allLocations = [];
			$nextPageToken = null;

			do {
				$response = Http::withToken($this->accessToken)
					->get("https://mybusiness.googleapis.com/v1/accounts/{$accountId}/locations", [
						'pageSize' => 100,
						'readMask' => implode(',', $readMask),
						'pageToken' => $nextPageToken,
					]);

				$responseJson  = $response->json();
				$locations     = $responseJson['locations'] ?? [];
				$allLocations  = array_merge($allLocations, $locations);
				$nextPageToken = $responseJson['nextPageToken'] ?? null;
			} while ($nextPageToken);

			return $allLocations;
		});

		return $locations;
	}

	public function getLocationByPlaceId($placeId) {
		if (!$placeId) {
			return null;
		}
		return collect($this->getLocations())
			->whereNull('metadata.duplicateLocation')
			->firstWhere('metadata.placeId', $placeId);
	}

	public function updateLocation(int|string $locationId, RegularHours $regularHours) {
		$accountId = $this->getAccountId();

		$response = Http::withToken($this->accessToken)
			->patch("https://mybusiness.googleapis.com/v1/accounts/{$accountId}/locations/{$locationId}", [
				'regularHours' => [
					'periods' => $regularHours->getPeriods(),
				]
			]);

		return $response->json();
	}

	public function getRegularHours(array $location) {
		return new RegularHours($location['regularHours']['periods'] ?? []);
	}

	public function getSpecialHours(array $location) {
		if (!empty($location['metadata']['placeId']) and $location['metadata']['placeId'] == 'ChIJTZin4K6gWkYRbErHkTdq05I') {
			dd($location);
		}
		return new SpecialHours($location['specialHours']['specialHourPeriods'] ?? []);
	}
}
