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
				fn($a) => $a['openTime']['minutes'] ?? 0,
			])
			->values();
	}

	public function getString(bool $includeLunch = true): string {
		$output = '';
		$dayMap = [
			'MONDAY' => 'Mon',
			'TUESDAY' => 'Tue',
			'WEDNESDAY' => 'Wed',
			'THURSDAY' => 'Thu',
			'FRIDAY' => 'Fri',
			'SATURDAY' => 'Sat',
			'SUNDAY' => 'Sun',
		];

		$groupedPeriods = $this->getPeriods()->groupBy('openDay');

		$combinedPeriods = [];

		foreach ($groupedPeriods as $day => $periods) {
			$openTimes = [];
			$closeTimes = [];

			foreach ($periods as $period) {
				$openTime = $period['openTime']['hours'] . ':' . str_pad($period['openTime']['minutes'] ?? 0, 2, '0', STR_PAD_LEFT);
				$closeTime = $period['closeTime']['hours'] . ':' . str_pad($period['closeTime']['minutes'] ?? 0, 2, '0', STR_PAD_LEFT);

				if ($includeLunch || (!$includeLunch && count($periods) == 1)) {
					$openTimes[] = $openTime;
					$closeTimes[] = $closeTime;
				} elseif (!$includeLunch) {
					// If not including lunch, take the earliest open time and the latest close time
					$openTimes[] = $openTime;
					$closeTimes[] = $closeTime;
				}
			}

			if (!$includeLunch) {
				// If not including lunch, take the earliest open time and the latest close time
				$openTimes = [min($openTimes)];
				$closeTimes = [max($closeTimes)];
			}

			$combinedPeriods[$day] = [
				'openTimes' => array_unique($openTimes),
				'closeTimes' => array_unique($closeTimes),
			];
		}

		$days = array_keys($combinedPeriods);

		if (!$days) {
			return $output;
		}

		$rangeStart = $days[0];
		$previousDay = $rangeStart;
		$rangeOpenTimes = $combinedPeriods[$rangeStart]['openTimes'];
		$rangeCloseTimes = $combinedPeriods[$rangeStart]['closeTimes'];

		for ($i = 1; $i < count($days); $i++) {
			$currentDay = $days[$i];
			$currentOpenTimes = $combinedPeriods[$currentDay]['openTimes'];
			$currentCloseTimes = $combinedPeriods[$currentDay]['closeTimes'];

			if ($currentOpenTimes === $rangeOpenTimes && $currentCloseTimes === $rangeCloseTimes) {
				$previousDay = $currentDay;
			} else {
				$output .= $this->formatRange($rangeStart, $previousDay, $rangeOpenTimes, $rangeCloseTimes, $dayMap) . ', ';
				$rangeStart = $currentDay;
				$previousDay = $currentDay;
				$rangeOpenTimes = $currentOpenTimes;
				$rangeCloseTimes = $currentCloseTimes;
			}
		}

		$output .= $this->formatRange($rangeStart, $previousDay, $rangeOpenTimes, $rangeCloseTimes, $dayMap);

		return $output;
	}


	protected function formatRange($startDay, $endDay, $openTimes, $closeTimes, $dayMap) {
		$dayString = $startDay === $endDay ? $dayMap[$startDay] : $dayMap[$startDay] . '-' . $dayMap[$endDay];
		$timeString = implode('/', $openTimes) . '-' . implode('/', $closeTimes);

		return "$dayString $timeString";
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
