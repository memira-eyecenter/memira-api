<?php

namespace App\Utilities;

use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class SpecialHours {
	protected Collection $periods;

	public function __construct(array $periods = []) {
		$this->periods = collect();

		foreach ($periods as $period) {
			// Extract values from the period array
			$isOpen    = isset($period['closed']) ? !$period['closed'] : true; // Default to open if 'closed' is not set
			$startDate = $period['startDate'];
			$endDate   = $period['endDate'] ?? null;
			$openTime  = $period['openTime'] ?? null;
			$closeTime = $period['closeTime'] ?? null;

			if (is_array($startDate) and isset($startDate['year'], $startDate['month'], $startDate['day'])) {
				if ($startDate['year'] > 0) {
					$startDate = Carbon::createFromDate($startDate['year'], $startDate['month'], $startDate['day']);
				} else {
					$this->periods->push($period);
					continue;
				}
			}

			if (is_array($endDate) and isset($endDate['year'], $endDate['month'], $endDate['day'])) {
				if ($endDate['year'] > 0) {
					$endDate = Carbon::createFromDate($endDate['year'], $endDate['month'], $endDate['day']);
				} else {
					$this->periods->push($period);
					continue;
				}
			}

			if (is_array($openTime) and isset($openTime['hours'])) {
				$openTime = Carbon::createFromTime($openTime['hours'], $openTime['minutes'] ?? null);
				$openTime = $openTime->format('H:i');
			}

			if (is_array($closeTime) and isset($closeTime['hours'])) {
				$closeTime = Carbon::createFromTime($closeTime['hours'], $closeTime['minutes'] ?? null);
				$closeTime = $closeTime->format('H:i');
			}

			// Add the period using the addPeriod method
			$this->addPeriod($isOpen, $startDate, $endDate, $openTime, $closeTime);
		}
	}

	public function addPeriod(bool $isOpen = true, string|Carbon $startDate, ?string $endDate = null, ?string $openTime = null, ?string $closeTime = null, ?DateTimeZone $timeZone = null) {
		// Parse date inputs
		$startDate = $this->parseDate($startDate, $timeZone);
		$endDate   = $this->parseDate($endDate ?? $startDate, $timeZone);

		// Ignore past end dates
		if ($endDate->isPast()) {
			return;
		}

		if (!$startDate->isSameDay($endDate) and $startDate->greaterThan($endDate)) {
			throw new \InvalidArgumentException('Invalid time period: start date must be before end date.');
		}

		// Construct the period array
		$period = [
			'startDate' => $this->formatDate($startDate),
			'endDate'   => $this->formatDate($endDate),
			'closed'    => !$isOpen,
		];

		if (!$isOpen) {
			// No need for endDate, openTime, or closeTime if closed
			$this->periods->push($period);
			return;
		}

		// Parse time inputs
		$openTime  = $this->parseTime($openTime, $startDate, $timeZone);
		$closeTime = $this->parseTime($closeTime, $startDate, $timeZone);

		// Validate the time period
		if ($isOpen and $openTime and $closeTime and $openTime->greaterThan($closeTime)) {
			throw new \InvalidArgumentException('Invalid time period: open time must be before close time.');
		}

		// Include open and close times for open periods
		$period['openTime']  = $openTime ? $this->formatTime($openTime) : null;
		$period['closeTime'] = $closeTime ? $this->formatTime($closeTime) : null;

		foreach (['endDate', 'openTime', 'closeTime'] as $field) {
			if ($period[$field] === null) {
				unset($period[$field]);
			}
		}

		// Push the period to the collection
		$this->periods->push($period);
	}

	public function addClosedDay(string|Carbon $date, ?DateTimeZone $timeZone = null) {
		$date = $this->parseDate($date, $timeZone);
		$this->addPeriod(false, $date);
	}

	public function addOpenDay(string|Carbon $date, ?DateTimeZone $timeZone = null) {
		$date = $this->parseDate($date, $timeZone);
		$this->addPeriod(true, $date);
	}

	protected function parseDate(string|Carbon $date, ?DateTimeZone $timeZone): Carbon {
		return is_string($date) ? Carbon::parse($date, $timeZone) : $date;
	}

	protected function parseTime(?string $time, Carbon $referenceDate, ?DateTimeZone $timeZone): ?Carbon {
		if (!$time) {
			return null; // If no time is provided, return null
		}

		// Check if the time is in "HH:MM" format
		if (preg_match('/^\d{1,2}:\d{2}/', $time)) {
			// Split the time into hours and minutes
			list($hours, $minutes) = explode(':', $time);

			// Create a Carbon instance with the specified hours and minutes
			return Carbon::createFromTime((int) $hours, (int) $minutes, 0, $timeZone)
				->setDate($referenceDate->year, $referenceDate->month, $referenceDate->day);
		}

		// If the time is not in the expected format, throw an exception
		throw new \InvalidArgumentException('Invalid time format provided. Expected "HH:MM".');
	}

	protected function formatDate(Carbon $date): array {
		return [
			'year'  => $date->year,
			'month' => $date->month,
			'day'   => $date->day,
		];
	}

	protected function formatTime(Carbon $time): array {
		$formattedTime = ['hours' => $time->hour];

		if ($time->minute) {
			$formattedTime['minutes'] = $time->minute;
		}

		return $formattedTime;
	}

	public function toString(): string {
		$closedDays = [];
		$openPeriods = [];

		foreach ($this->periods as $period) {
			if ($period['closed']) {
				// Format closed days using startDate
				$startDate = Carbon::createFromDate(
					$period['startDate']['year'],
					$period['startDate']['month'],
					$period['startDate']['day']
				);
				if ($period['endDate']) {
					$endDate = Carbon::createFromDate(
						$period['endDate']['year'],
						$period['endDate']['month'],
						$period['endDate']['day']
					);
				}
				if (empty($endDate) or $startDate->isSameDay($endDate)) {
					$closedDays[] = $startDate->format('M j'); // e.g., "Aug 25"
				} else {
					$closedDays[] = $startDate->format('M j') . '-' . $endDate->format('M j'); // e.g., "Aug 25-Sep 1"
				}
			} else {
				// Format open periods using startDate
				$date = Carbon::createFromDate(
					$period['startDate']['year'],
					$period['startDate']['month'],
					$period['startDate']['day']
				);

				// Check if openTime and closeTime are set
				$openTime  = $period['openTime'] ?? null;
				$closeTime = $period['closeTime'] ?? null;

				// Only include time if both openTime and closeTime are present
				if ($openTime && $closeTime) {
					$formattedOpenTime  = sprintf('%02d:%02d', $openTime['hours'], $openTime['minutes'] ?? 0);
					$formattedCloseTime = sprintf('%02d:%02d', $closeTime['hours'], $closeTime['minutes'] ?? 0);
					$openPeriods[] = sprintf('%s %s-%s', $date->format('j M'), $formattedOpenTime, $formattedCloseTime);
				} else {
					// If only the date is required, you can format it differently or just add the date
					$openPeriods[] = sprintf('%s', $date->format('j M')); // Just show the date if no time is provided
				}
			}
		}

		// Process closed days to handle ranges
		$closedDays = array_unique($closedDays);
		sort($closedDays); // Sort closed days for better formatting
		$closedDaysFormatted = $this->formatClosedDays($closedDays);

		// Prepare final output string
		$output = '';
		if (!empty($closedDaysFormatted)) {
			$output .= "Closed: $closedDaysFormatted. ";
		}
		if (!empty($openPeriods)) {
			$output .= "Open: " . implode(', ', $openPeriods) . ".";
		}

		return trim($output); // Return trimmed output
	}

	protected function formatClosedDays(array $closedDays): string {
		// Group closed days into ranges
		$result = [];
		$currentRange = [];

		foreach ($closedDays as $day) {
			if (empty($currentRange)) {
				$currentRange[] = $day;
			} elseif (strtotime($day) === strtotime(end($currentRange)) + 86400) { // Check if it's the next day
				$currentRange[] = $day;
			} else {
				// If range ends, add to result
				$result[] = $this->formatRange($currentRange);
				$currentRange = [$day]; // Start new range
			}
		}

		// Add the last range if exists
		if (!empty($currentRange)) {
			$result[] = $this->formatRange($currentRange);
		}

		return implode(', ', $result);
	}

	protected function formatRange(array $days): string {
		if (count($days) === 1) {
			return $days[0]; // Single day
		}
		return sprintf('%s-%s', $days[0], end($days)); // Range
	}

	public function getPeriods(): Collection {
		return $this->periods
			->sortBy([
				// Sort by date from startDate, with error handling
				fn($a) => isset($a['startDate']['year'], $a['startDate']['month'], $a['startDate']['day'])
					? (Carbon::createFromDate($a['startDate']['year'], $a['startDate']['month'], $a['startDate']['day']))->format('Y-m-d')
					: (Carbon::now())->format('Y-m-d'), // Fallback to now if not set
				// Sort by open time (default to 0 if not set)
				fn($a) => $a['openTime']['hours'] ?? 0,
				fn($a) => $a['openTime']['minutes'] ?? 0,
			])
			->values();
	}

	public function toJson(): string {
		return $this->getPeriods()->toJson();
	}

	public function toArray(): array {
		return $this->getPeriods()->toArray();
	}

	public function getHash(): string {
		return hash('sha256', $this->toJson());
	}

	public function compare(SpecialHours $other): bool {
		return $this->getHash() === $other->getHash();
	}
}
