<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleService;

class HandleGoogleInvitations extends Command {
	// The name and signature of the console command.
	protected $signature = 'app:google:invitations {--no-cache}';

	// The console command description.
	protected $description = 'Fetch and handle invitations from Google Business';

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

		$accountIds = collect($accounts)
			->pluck('name')
			->map(function ($name) {
				$name = explode('/', $name);
				return end($name);
			})
			->all();

		$allInvitations = [];

		foreach ($accountIds as $accountId) {
			$invitations = $this->googleService->getInvitations($accountId);

			if (!$invitations) {
				continue;
			}

			$allInvitations = array_merge($allInvitations, $invitations);
		}

		if (!$allInvitations) {
			$this->info('Google - No invitations found or error fetching invitations.');
			return;
		}

		collect($allInvitations)
			->map(function ($invitation) {
				$found = preg_match('/accounts\/([^\/]+)\/invitations\/([^\/]+)/', $invitation->get('name'), $matches);

				$invitation['accountId']    = $found ? $matches[1] : null;
				$invitation['invitationId'] = $found ? $matches[2] : null;

				return $invitation;
			})
			->unique('invitationId')
			->each(function ($invitation) {
				$invitation = collect($invitation)->dot();

				$name = $invitation->get('targetAccount.accountName') ?? $invitation->get('targetAccount.name') ?? $invitation->get('name');
				$role = $invitation->get('role') ?? 'UNKNOWN';

				if (!$this->confirm(sprintf('Do you accept the invitation to %1$s as %2$s', $name, $role))) {
					return;
				}

				if (!$invitation->get('accountId') or $invitation->get('invitationId')) {
					$this->error('Invalid invitation name: ' . $invitation->get('name'));
					return;
				}


				if (!$this->googleService->acceptInvitation($invitation->get('accountId'), $invitation->get('invitationId'))) {
					$this->error('Error accepting invitation to ' . $name . ' (' . $invitation->get('name') . ')');
					return;
				}

				$this->info('Accepted invitation to ' . $name . ' as ' . $role);
			});

		$this->info('All invitations handled');
	}
}
