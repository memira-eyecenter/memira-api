<?php

namespace App\Utilities;

use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class RegularHours {
	protected Collection $periods;

	public function __construct($periods = []) {
		$this->periods = collect($periods);
	}

	public function addPeriod(string $day, string|Carbon $openTime, string|Carbon $closeTime, ?DateTimeZone $timeZone = null) {
		if (is_string($openTime)) {
			$openTime = Carbon::parse($openTime, $timeZone);
		}

		if (is_string($closeTime)) {
			$closeTime = Carbon::parse($closeTime, $timeZone);
		}

		$period = [
			'openDay'  => strtoupper($day),
			'openTime' => [
				'hours' => $openTime->hour,
			],
			'closeDay'  => strtoupper($day),
			'closeTime' => [
				'hours' => $closeTime->hour,
			],
		];

		if ($openTime->minute) {
			$period['openTime']['minutes'] = $openTime->minute;
		}

		if ($closeTime->minute) {
			$period['closeTime']['minutes'] = $closeTime->minute;
		}

		$this->periods->push($period);
	}

	public function getPeriods(): Collection {
		return $this->periods
			->sortBy([
				fn($a) => $a['openDay'],
				fn($a) => $a['openTime']['hours'],
				fn($a) => $a['openTime']['minutes']
			])
			->values();
	}

	public function toJson(): string {
		return $this->getPeriods()->toJson();
	}

	public function getHash(): string {
		return hash('sha256', $this->toJson());
	}

	public function compare(RegularHours $other): bool {
		return $this->getHash() === $other->getHash();
	}
}
