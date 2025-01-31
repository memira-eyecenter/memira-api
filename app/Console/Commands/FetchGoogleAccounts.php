<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleService;

class FetchGoogleAccounts extends Command {
	// The name and signature of the console command.
	protected $signature = 'app:google:accounts {--no-cache}';

	// The console command description.
	protected $description = 'Fetch accounts from Google Business';

	// GoogleService instance
	protected $googleService;

	// Inject GoogleService into the command
	public function __construct(GoogleService $googleService) {
		parent::__construct();

		$this->googleService = $googleService;
	}

	// Execute the console command.
	public function handle() {
		$accounts = $this->googleService->getAccounts(!empty($this->option('no-cache')));

		if (!$accounts) {
			$this->info('Google - No accounts found or error fetching accounts.');
			return;
		}

		$accounts = collect($accounts)
			->filter(function ($account) {
				return $account['type'] === 'LOCATION_GROUP';
			})
			->values();

		$this->table(array_keys($accounts->get(0)), $accounts);
	}
}
