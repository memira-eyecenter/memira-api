<?php

namespace App\Utilities;

use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class RegularHours {
	protected Collection $periods;

	// Constants for days of the week
	private const DAYS = [
		'MONDAY'    => 'Mon',
		'TUESDAY'   => 'Tue',
		'WEDNESDAY' => 'Wed',
		'THURSDAY'  => 'Thu',
		'FRIDAY'    => 'Fri',
		'SATURDAY'  => 'Sat',
		'SUNDAY'    => 'Sun',
	];

	public function __construct(array $periods = []) {
		$this->periods = collect();

		foreach ($periods as $period) {
			// Ensure required fields are provided
			$openDay    = $period['openDay'] ?? throw new \InvalidArgumentException('Open day is required.');
			$openTime   = $period['openTime'] ?? throw new \InvalidArgumentException('Open time is required.');
			$closeDay   = $period['closeDay'] ?? throw new \InvalidArgumentException('Close day is required.');
			$closeTime  = $period['closeTime'] ?? throw new \InvalidArgumentException('Close time is required.');

			// Validate that openTime and closeTime contain the required structure
			if (!isset($openTime['hours'])) {
				throw new \InvalidArgumentException('Open time must contain hours.');
			}
			if (!isset($closeTime['hours'])) {
				throw new \InvalidArgumentException('Close time must contain hours.');
			}

			// Convert openTime and closeTime to Carbon instances
			$openTimeCarbon = Carbon::createFromTime($openTime['hours'], $openTime['minutes'] ?? 0);
			$closeTimeCarbon = Carbon::createFromTime($closeTime['hours'], $closeTime['minutes'] ?? 0);

			// Add the period using the addPeriod method
			$this->addPeriod($openDay, $openTimeCarbon, $closeDay, $closeTimeCarbon);
		}
	}

	public function addPeriod(string $openDay, Carbon $openTime, ?string $closeDay = null, ?Carbon $closeTime = null, ?DateTimeZone $timeZone = null) {
		// Validate openDay
		$openDay = strtoupper($openDay);
		if (!array_key_exists($openDay, self::DAYS)) {
			throw new \InvalidArgumentException('Invalid open day provided.');
		}

		// Default closeDay to openDay if not provided
		$closeDay = $closeDay ? strtoupper($closeDay) : $openDay;

		// Validate closeDay
		if (!array_key_exists($closeDay, self::DAYS)) {
			throw new \InvalidArgumentException('Invalid close day provided.');
		}

		// Validate that openTime is before closeTime
		if ($closeTime === null) {
			throw new \InvalidArgumentException('Close time is required.');
		}

		if ($openTime->greaterThan($closeTime)) {
			throw new \InvalidArgumentException('Open time must be before close time.');
		}

		// Construct the period array
		$period = [
			'openDay'   => $openDay,
			'openTime'  => $this->formatTime($openTime),
			'closeDay'  => $closeDay,
			'closeTime' => $this->formatTime($closeTime),
		];

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

	public function toString(bool $includeLunch = true): string {
		$output          = '';
		$groupedPeriods  = $this->getPeriods()->groupBy('openDay');
		$combinedPeriods = [];

		foreach ($groupedPeriods as $day => $periods) {
			$openTimes  = [];
			$closeTimes = [];

			foreach ($periods as $period) {
				$openTime  = sprintf('%02d:%02d', $period['openTime']['hours'], $period['openTime']['minutes'] ?? 0);
				$closeTime = sprintf('%02d:%02d', $period['closeTime']['hours'], $period['closeTime']['minutes'] ?? 0);

				$openTimes[]  = $openTime;
				$closeTimes[] = $closeTime;
			}

			// Format the output based on whether lunch is included
			if ($includeLunch) {
				// If lunch is included, format as "08:00-12:00/13:00-17:00"
				$combinedPeriods[$day] = sprintf('%s %s', self::DAYS[$day], $this->formatOpenCloseWithLunch($openTimes, $closeTimes));
			} else {
				// If lunch is not included, format as "08:00-17:00"
				$minOpenTime = min($openTimes);
				$maxCloseTime = max($closeTimes);
				$combinedPeriods[$day] = sprintf('%s %s-%s', self::DAYS[$day], $minOpenTime, $maxCloseTime);
			}
		}

		// Group days by their time strings
		$timesWithDays = array_reduce($combinedPeriods, function ($carry, $item) {
			[$day, $times] = explode(' ', $item);

			if (!isset($carry[$times])) {
				$carry[$times] = [];
			}

			$carry[$times][] = $day;
			return $carry;
		}, []);

		// Prepare the final output string
		$finalOutput = [];
		foreach ($timesWithDays as $times => $days) {
			$startDay = $days[0];
			$endDay = end($days);

			// Format the output based on whether they are the same day or a range
			if ($startDay === $endDay) {
				$finalOutput[] = sprintf('%s %s', $startDay, $times);
			} else {
				$finalOutput[] = sprintf('%s-%s %s', $startDay, $endDay, $times);
			}
		}

		return implode(', ', $finalOutput);
	}

	protected function formatOpenCloseWithLunch(array $openTimes, array $closeTimes): string {
		$formatted = [];

		// Loop through the open and close times
		for ($i = 0; $i < count($openTimes); $i++) {
			if (isset($closeTimes[$i])) {
				// Format as "openTime-closeTime"
				$formatted[] = sprintf('%s-%s', $openTimes[$i], $closeTimes[$i]);
			}
		}

		// Join the formatted times with a slash
		return implode('/', $formatted);
	}


	protected function parseTime(string|Carbon $time, ?DateTimeZone $timeZone): Carbon {
		return is_string($time) ? Carbon::parse($time, $timeZone) : $time;
	}

	protected function formatTime(Carbon $time): array {
		return [
			'hours'   => $time->hour,
			'minutes' => $time->minute ?? 0,
		];
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
