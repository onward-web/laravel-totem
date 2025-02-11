<?php

namespace Studio\Totem\Traits;

use Closure;
use Illuminate\Console\Scheduling\ManagesFrequencies;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use function json_decode;
use function request;
use Studio\Totem\Frequency;
use Studio\Totem\Task;

trait HasFrequencies
{
    use ManagesFrequencies;

    /**
     * The array of filter callbacks.
     *
     * @var array
     */
    protected array $filters = [];

    /**
     * The array of reject callbacks.
     *
     * @var array
     */
    protected array $rejects = [];

    /**
     * Boot HasFrequencies Trait.
     */
    public static function bootHasFrequencies()
    {
        static::saved(function ($model) {
            $model->afterSave();
        });

        static::deleting(function ($model) {
            $model->beforeDelete();
        });
    }

    /**
     * When task is updated or created, we grab the input. If the type is set to frequency in input we try to either
     * update or create the frequencies included in input else delete the frequency. If the type is not frequency and
     * the task in question has frequencies saved in databased, delete them all.
     */
    public function afterSave()
    {
        $input = $this->processData();

        if (isset($input['type'])) {
            if ($input['type'] == 'frequency') {
                foreach ($this->frequencies as $frequency) {
                    if (! in_array($frequency->interval, collect($input['frequencies'])->pluck('interval')->toArray())) {
                        $frequency->delete();
                    }
                }

                foreach ($input['frequencies'] as $_frequency) {
                    $this->frequencies()->updateOrCreate(Arr::only($_frequency, ['task_id', 'label', 'interval']));
                }
            } else {
                $this->frequencies->each(function ($frequency) {
                    $frequency->delete();
                });
            }
        }
    }

    /**
     * Task Deleted.
     */
    public function beforeDelete()
    {
        $this->frequencies->each(function ($frequency) {
            $frequency->delete();
        });

        $this->results()->delete();
    }

    /**
     * Frequencies Relation.
     *
     * @return HasMany
     */
    public function frequencies(): HasMany
    {
        return $this->hasMany(Frequency::class, 'task_id', 'id')->with('parameters');
    }

    /**
     * Generate a cron expression from frequencies.
     *
     * @return string
     */
    public function getCronExpression(): string
    {
        if (! $this->expression) {
            $this->expression = '* * * * *';

            foreach ($this->frequencies as $frequency) {
                call_user_func_array([$this, $frequency->interval], $frequency->parameters->pluck('value')->toArray());
            }

            $expression = $this->expression;

            $this->expression = null;

            return $expression;
        }

        return $this->expression;
    }

    /**
     * Determine if the filters pass for the event.
     *
     * @param  Application  $app
     * @return bool
     */
    public function filtersPass(Application $app): bool
    {
        foreach ($this->filters as $callback) {
            if (! $app->call($callback)) {
                return false;
            }
        }

        foreach ($this->rejects as $callback) {
            if ($app->call($callback)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Register a callback to further filter the schedule.
     *
     * @param  Closure  $callback
     * @return $this
     */
    public function when(Closure $callback): static
    {
        $this->filters[] = $callback;

        return $this;
    }

    /**
     * Schedule the event to run between start and end time.
     *
     * @param  string  $startTime
     * @param  string  $endTime
     * @return $this
     */
    public function between($startTime, $endTime): static
    {
        return $this->when($this->inTimeInterval($startTime, $endTime));
    }

    /**
     * Process input data. If its an import action we must find out if the imported json has frequencies or not and
     * prepare data accordingly.
     *
     * @throws FileNotFoundException
     */
    private function processData(): array
    {
        $data = defined('MANUAL_TASK_ADD') ? MANUAL_TASK_ADD :  request()->all();

        if (! request()->hasFile('tasks')) {
            return $data;
        }

        $task = collect(json_decode(request()->file('tasks')->get()))
            ->filter(function ($task) {
                return $task->id === $this->id;
            })
            ->first();

        if ($task && ($task->frequencies ?? false)) {
            $data['type'] = 'frequency';
            $data['frequencies'] = collect($task->frequencies)->map(function ($frequency) {
                return (array) $frequency;
            })->toArray();
        }

        return $data;
    }
}
