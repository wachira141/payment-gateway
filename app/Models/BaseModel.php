<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Utils\CurrencyConverter;

// use Laravel\Scout\Searchable;

abstract class BaseModel extends Model
{
    // use HasFactory, Searchable;
    use HasFactory;

    protected $primaryKey = 'id'; // Ensure primary key is 'id'
    protected $keyType = 'string'; // Set key type to string
    public $incrementing = false; // Disable auto-incrementin

    // public static BASE_CURRENCY = 'EUR'; // Default to EUR
    public const BASE_CURRENCY = 'EUR';


    /**
     * Define date attributes that should be formatted as 'Y-m-d'.
     * Models can override this.
     */
    protected array $dateFormats = [];


    protected static function boot()
    {
        parent::boot();

        // Create a UUID for the model on creation
        static::creating(function ($model) {
            if (!$model->getKey()) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     *
     * @return bool
     */
    public function getIncrementing()
    {
        return false;
    }

    /**
     * Get the auto-incrementing key type.
     *
     * @return string
     */
    public function getKeyType()
    {
        return 'string';
    }

    /**
     * Perform a fulltext search on the model.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $columns
     * @param  string  $term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    // public function scopeSearch($query, array $columns, string $term)
    // {
    //     $query->where(function ($query) use ($columns, $term) {
    //         foreach ($columns as $column) {
    //             $query->orWhere($column, 'LIKE', "%{$term}%");
    //         }
    //     });

    //     return $query;
    // }

    public  function generateUniqNumber()
    {
        $uniq = Str::uuid()->toString();
        return $uniq;
    }
    /**
     * Specify the default methods and properties
     * that all models extending BaseModel will inherit.
     */

    // Common attributes and configurations
    protected $guarded = []; // Default to an empty guarded array
    /**
     * Convert the model to an array with specific fields excluded.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        // Define relationships to load
        $with = $this->defaultRelationshipsToLoad();

        // Load relationships if not already loaded
        $this->loadMissing($with);

        // Convert the model instance to an array
        $array = $this->toArray();

        // Specify the fields to exclude
        $excludedFields = $this->getExcludedFields();

        // Remove the excluded fields from the array
        foreach ($excludedFields as $field) {
            unset($array[$field]);
        }

        return $array;
    }

    /**
     * Get an array of default relationships to load.
     * Override this in child models to customize.
     *
     * @return array
     */
    protected function defaultRelationshipsToLoad()
    {
        return []; // Default is no relationships
    }

    /**
     * Get an array of fields to exclude from the searchable array.
     * Override this in child models to customize.
     *
     * @return array
     */
    protected function getExcludedFields()
    {
        return []; // Default is no excluded fields
    }

    public static function formatDate($dateString)
    {
        return Carbon::parse($dateString)->format('M d, Y');
    }

    public static function formatTime($timeString)
    {
        return Carbon::createFromFormat('H:i:s', $timeString)->format('h:i A');
    }

    function getTimeFromISO8601($timestamp)
    {
        // Create a DateTime object from the timestamp
        $date = new \DateTime($timestamp);

        // Format the time as H:i:s
        $formattedTime = $date->format('H:i:s');

        return $formattedTime;
    }

    protected function convertToUtc(string $datetime): string
    {
        $timezone = new \DateTimeZone(config('app.timezone')); // Replace with SES_TIMEZONE if needed
        $date = new \DateTime($datetime, $timezone);
        $date->setTimezone(new \DateTimeZone('UTC'));
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Customize the serialization of dates.
     *
     * @param  string  $attribute
     * @return string|null
     */
    public function serializeDateAttribute($attribute)
    {
        // Check if the attribute exists and is an instance of Carbon
        if (isset($this->{$attribute}) && $this->{$attribute} instanceof Carbon) {
            // Return the formatted date using the specified format
            return $this->{$attribute}->format($this->dateFormats[$attribute] ?? 'Y-m-d H:i:s');
        }

        // If the attribute does not exist or is not a Carbon instance, return null
        return null;
    }


    /**
     * Customize the serialization of dates.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        foreach ($this->dateFormats as $attribute => $format) {
            if ($this->{$attribute} instanceof Carbon && $this->{$attribute}->equalTo($date)) {
                return $date->format($format);
            }
        }

        // Default to parent serialization if no format is defined
        return parent::serializeDate($date);
    }

       /**
     * Generate correlation ID for tracking data on end-to-end
     */
    public static function generateCorrelationId(string $endPoint): string
    {
        return $endPoint . Str::random(32);
    }


    // protected static function convertCurrency(): CurrencyConverter
    // {
    //     return new CurrencyConverter();
    // }

    // /**
    //  * Converts the given expense amount to the base currency.
    //  *
    //  * This method utilizes the CurrencyConverter class to transform an expense's
    //  * reimbursable amount from its original currency to the system's base currency.
    //  * The conversion process involves:
    //  * 1. Instantiating a CurrencyConverter object.
    //  * 2. Setting the user's currency based on the expense's currency identifier.
    //  *
    //  * @return float The equivalent amount in the base currency.
    //  */
    // protected function convertToBaseCurrency(float $amount, string $currencyId = null): float
    // {
    //     $currencyConverter = self::convertCurrency();
    //     $currencyConverter->setUserCurrency($currencyId ?? self::BASE_CURRENCY);
    //     return $currencyConverter->convertToBaseCurrency($amount);
    // }

    // public static function convertToUserCurrency(float $amount, string $currencyId = null): float
    // {
    //     $currencyConverter = self::convertCurrency();
    //     $currencyConverter->setUserCurrency($currencyId ?? self::BASE_CURRENCY);
    //     return $currencyConverter->convertToUserCurrency($amount);
    // }

    // public static function getExchangeRate(string $fromCurrency, string $toCurrency): float
    // {
    //     $currencyConverter = self::convertCurrency();
    //     return $currencyConverter->getExchangeRate($fromCurrency, $toCurrency);
    // }
    /**
     * Convert seconds to hours and minutes format (hrs:min).
     *
     * @param int $totalSeconds
     * @return string
     */
    public static function convertSecondsToHrsMin($totalSeconds)
    {
        // Calculate hours and minutes
        $hours = floor($totalSeconds / 3600); // 1 hour = 3600 seconds
        $minutes = floor(($totalSeconds % 3600) / 60); // Remainder after dividing by 3600 gives remaining seconds, then convert to minutes

        // Format the result as "hrs:min" with leading zeroes for single digits
        return sprintf('%02d:%02d', $hours, $minutes);
    }

    public static function convertHrsMinToSeconds($time)
    {
        if (strpos($time, ':') !== false) {
            list($hours, $minutes) = explode(':', $time);
        } else if (strpos($time, '.') !== false) {
            list($hours, $minutes) = explode('.', $time);
        } else {
            $hours = $time;
            $minutes = 0;
        }
        $totalSeconds = ($hours * 3600) + ($minutes * 60);
        return $totalSeconds;
    }

    /**
     *
     */

    public static function calculateHours(string $projectId, string $taskId)
    {
        $billableHours = self::where('is_billable', 1)
            ->where('project_id', $projectId)
            ->where('task_id', $taskId)
            ->sum('total_hours');

        $nonBillableHours = self::where('is_billable', 0)
            ->where('project_id', $projectId)
            ->where('task_id', $taskId)
            ->sum('total_hours');

        return [$billableHours, $nonBillableHours];
    }

    /**
     * Get the allowed preparation statuses.
     */
    public static function preparationStatuses()
    {
        return [
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            'review' => 'Under Review',
            'finalizing' => 'Finalizing',
            'completed' => 'Completed'
        ];
    }

    /**
     * Update the preparation status of a goal request.
     */
    public function updatePreparationStatus($status)
    {
        $allowedStatuses = array_keys(self::preparationStatuses());

        if (!in_array($status, $allowedStatuses)) {
            throw new \InvalidArgumentException("Invalid preparation status: {$status}");
        }

        $this->preparation_status = $status;

        // Set the start date if this is the first status update
        if ($status === 'in_progress' && is_null($this->preparation_started_at)) {
            $this->preparation_started_at = now();
        }

        // Set the completion date if status is completed
        if ($status === 'completed') {
            // Ensure goal relationship is loaded
            if ($this->goal) {
                $goal = $this->goal; // Get the goal instance
                $goal->status = 'not-started';
                $goal->save(); // Save changes to the goal
            }
            $this->preparation_completed_at = now();
        }

        return $this->save();
    }


    /**
     * Find model by field value
     */
    public static function findByField($field, $value)
    {
        return static::where($field, $value)->first();
    }

    /**
     * Create or update model
     */
    public static function createOrUpdate(array $attributes, array $values = [])
    {
        return static::updateOrCreate($attributes, $values);
    }

    /**
     * Get paginated results with filters
     */
    public static function getPaginated(array $filters = [], $perPage = 15)
    {
        $query = static::query();

        foreach ($filters as $field => $value) {
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        return $query->paginate($perPage);
    }

    /**
     * Mark record as processed
     */
    public function markProcessed()
    {
        return $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }


    /**
     * Mark record as failed
     * 
     * @param mixed $error The error message or data
     * @param array $additionalData Any additional data to save
     * @return bool
     */
    // public function markFailed($error, array $additionalData = [])
    // {
    //     return $this->update(array_merge([
    //         'status' => 'failed',
    //         'processing_error' => $error,
    //         'retry_count' => ($this->retry_count ?? 0) + 1,
    //     ], $additionalData));
    // }
}
