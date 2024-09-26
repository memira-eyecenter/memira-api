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
			$openTimes  = $this->extractTimes($periods->toArray(), 'openTime', $includeLunch);
			$closeTimes = $this->extractTimes($periods->toArray(), 'closeTime', $includeLunch);

			$combinedPeriods[$day] = [
				'openTimes'  => array_unique($openTimes),
				'closeTimes' => array_unique($closeTimes),
			];
		}

		$days = array_keys($combinedPeriods);
		if (empty($days)) return $output;

		$rangeStart     = $days[0];
		$previousDay    = $rangeStart;
		$rangeOpenTimes = $combinedPeriods[$rangeStart]['openTimes'];
		$rangeCloseTimes = $combinedPeriods[$rangeStart]['closeTimes'];

		for ($i = 1; $i < count($days); $i++) {
			$currentDay         = $days[$i];
			$currentOpenTimes   = $combinedPeriods[$currentDay]['openTimes'];
			$currentCloseTimes  = $combinedPeriods[$currentDay]['closeTimes'];

			if ($currentOpenTimes === $rangeOpenTimes and $currentCloseTimes === $rangeCloseTimes) {
				$previousDay = $currentDay;
			} else {
				$output .= $this->formatRange($rangeStart, $previousDay, $rangeOpenTimes, $rangeCloseTimes) . ', ';
				$rangeStart      = $currentDay;
				$previousDay     = $currentDay;
				$rangeOpenTimes  = $currentOpenTimes;
				$rangeCloseTimes = $currentCloseTimes;
			}
		}

		$output .= $this->formatRange($rangeStart, $previousDay, $rangeOpenTimes, $rangeCloseTimes);
		return $output;
	}

	protected function extractTimes(array $periods, string $timeType, bool $includeLunch): array {
		$times = [];
		foreach ($periods as $period) {
			$time = $period[$timeType]['hours'] . ':' . str_pad($period[$timeType]['minutes'] ?? 0, 2, '0', STR_PAD_LEFT);
			if ($includeLunch || (!$includeLunch and count($periods) == 1)) {
				$times[] = $time;
			}
		}

		if (!$includeLunch) {
			// Check if times array is not empty before calling min/max
			if (!empty($times)) {
				return [min($times), max($times)];
			}
			// Return a default value if times is empty
			return ['00:00', '00:00']; // Default to midnight if no times are available
		}

		return $times;
	}


	protected function formatRange(string $startDay, string $endDay, array $openTimes, array $closeTimes): string {
		$dayString = $startDay === $endDay ? self::DAYS[$startDay] : self::DAYS[$startDay] . '-' . self::DAYS[$endDay];
		$timeString = implode('/', $openTimes) . '-' . implode('/', $closeTimes);
		return "$dayString $timeString";
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
