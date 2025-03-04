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

		$jsonConfig = env('GOOGLE_BUSINESS_CREDENTIALS_JSON', null);

		if ($jsonConfig) {
			// Replace "XXLFXX" with "\n" to handle Kinsta environment variables replacing \n to n
			$jsonConfig = str_replace("XXLFXX", "\\n", $jsonConfig);
			$jsonConfig = json_decode($jsonConfig, true);
		}

		$config = $jsonConfig ?: config('services.google.credentials_path');

		$this->client->setAuthConfig($config);
		$this->client->addScope('https://www.googleapis.com/auth/business.manage');
		$this->client->useApplicationDefaultCredentials();

		$this->accessToken = $this->client->fetchAccessTokenWithAssertion()['access_token'];
	}

	public function getInvitations(string|int $accountId) {
		$invitations = Cache::remember("google/invitations", 10, function () use ($accountId) {
			$response = Http::withToken($this->accessToken)
				->get("https://mybusinessaccountmanagement.googleapis.com/v1/accounts/{$accountId}/invitations");

			return $response->json('invitations');
		});

		return $invitations;
	}

	public function acceptInvitation(string|int $accountId, string|int $invitationId): bool {
		// Accept invitation with empty body
		$response = Http::withToken($this->accessToken)
			->post("https://mybusinessaccountmanagement.googleapis.com/v1/accounts/{$accountId}/invitations/{$invitationId}:accept", null);

		return $response->successful();
	}

	public function getAccounts(bool $forceUpdate = false) {
		if ($forceUpdate) {
			Cache::forget('google/accounts');
		}

		$accounts = Cache::remember("google/accounts", 300, function () {
			$response = Http::withToken($this->accessToken)
				->get("https://mybusinessaccountmanagement.googleapis.com/v1/accounts");

			return $response->json('accounts');
		});

		return $accounts;
	}

	public function getAccountIds() {
		if (getenv('GOOGLE_BUSINESS_ACCOUNT_ID')) {
			$accountIds = explode(',', getenv('GOOGLE_BUSINESS_ACCOUNT_ID'));
			return array_map('trim', $accountIds);
		}

		return collect($this->getAccounts())
			->filter(function ($account) {
				// Only Memira accounts and not NL accounts
				return preg_match('/Memira/', $account['accountName']) and !preg_match('/NL/', $account['accountName']);
			})
			->pluck('name')
			->map(function ($name) {
				$name = explode('/', $name);
				return end($name);
			})
			->all();
	}

	public function getLocations(bool $forceUpdate = false) {
		$accountIds = $this->getAccountIds();

		if ($forceUpdate) {
			Cache::forget('google/locations');
		}

		$locations = Cache::remember("google/locations", 300, function () use ($accountIds) {
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

			foreach ($accountIds as $accountId) {
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
			}

			return $allLocations;
		});

		return $locations;
	}

	public function getLocationByPlaceId(string $placeId): array|string {
		if (!$placeId) {
			return null;
		}
		return collect($this->getLocations())
			->whereNull('metadata.duplicateLocation')
			->firstWhere('metadata.placeId', $placeId);
	}

	public function updateLocation(string $locationName, ?RegularHours $regularHours = null, ?SpecialHours $specialHours = null) {
		if (!$regularHours and !$specialHours) {
			throw new \Exception('At least one of regularHours or specialHours must be provided');
		}

		$body       = [];
		$updateMask = [];

		if ($regularHours) {
			$updateMask[] = 'regularHours.periods';
			$body['regularHours'] = [
				'periods' => $regularHours->toArray(),
			];
		}

		if ($specialHours) {
			$updateMask[] = 'specialHours.specialHourPeriods';
			$body['specialHours'] = [
				'specialHourPeriods' => $specialHours->toArray(),
			];
		}

		// dd($specialHours->toJson());

		$response = Http::withToken($this->accessToken)
			->withOptions(['debug' => true])
			->withQueryParameters([
				'updateMask' => implode(',', $updateMask),
			])
			->patch("https://mybusinessbusinessinformation.googleapis.com/v1/{$locationName}", $body);

		if (!$response->ok()) {
			throw new \Exception('Failed to update. Status: ' . $response->status() . ' Body: ' . $response->json() ?: $response->body());
		}

		Cache::forget('google/locations');

		return $response->json();
	}

	public function getRegularHours(array $location) {
		return new RegularHours($location['regularHours']['periods'] ?? []);
	}

	public function getSpecialHours(array $location) {
		return new SpecialHours($location['specialHours']['specialHourPeriods'] ?? []);
	}
}
