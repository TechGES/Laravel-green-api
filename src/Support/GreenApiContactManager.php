<?php

namespace Ges\LaravelGreenApi\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class GreenApiContactManager
{
    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        $modelClass = config('green_api.contact_model');

        if (! is_string($modelClass) || $modelClass === '') {
            throw new RuntimeException('Green API contact model is not configured.');
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new RuntimeException('Green API contact model must extend Eloquent Model.');
        }

        return $modelClass;
    }

    public function phoneAttribute(): string
    {
        $attribute = config('green_api.contact_phone_attribute', 'phone');

        return is_string($attribute) && $attribute !== '' ? $attribute : 'phone';
    }

    public function nameAttribute(): string
    {
        $attribute = config('green_api.contact_name_attribute', 'name');

        return is_string($attribute) && $attribute !== '' ? $attribute : 'name';
    }

    /**
     * @return list<string>
     */
    public function searchAttributes(): array
    {
        $attributes = config('green_api.contact_search_attributes', [
            $this->nameAttribute(),
            'email',
            $this->phoneAttribute(),
        ]);

        if (! is_array($attributes) || $attributes === []) {
            return [
                $this->nameAttribute(),
                'email',
                $this->phoneAttribute(),
            ];
        }

        return array_values(array_filter($attributes, fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    /**
     * @return EloquentCollection<int, Model>
     */
    public function contacts(): EloquentCollection
    {
        $modelClass = $this->modelClass();

        return $modelClass::query()
            ->whereNotNull($this->phoneAttribute())
            ->get();
    }

    public function find(int|string $id): ?Model
    {
        $modelClass = $this->modelClass();

        return $modelClass::query()->find($id);
    }

    public function findOrFail(int|string $id): Model
    {
        $modelClass = $this->modelClass();

        return $modelClass::query()->findOrFail($id);
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->contacts()
            ->sortBy(fn (Model $contact): string => Str::lower($this->label($contact)))
            ->mapWithKeys(fn (Model $contact): array => [
                (string) $contact->getKey() => "{$this->label($contact)} ({$this->phone($contact)})",
            ])
            ->all();
    }

    public function label(Model $contact): string
    {
        $candidates = [
            data_get($contact, $this->nameAttribute()),
            data_get($contact, 'full_name'),
            data_get($contact, 'name'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return class_basename($contact).' #'.$contact->getKey();
    }

    public function phone(Model $contact): string
    {
        $value = data_get($contact, $this->phoneAttribute());

        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new RuntimeException('Green API contact phone is missing.');
        }

        return trim((string) $value);
    }

    public function initials(Model $contact): string
    {
        if (method_exists($contact, 'initials')) {
            $initials = $contact->initials();

            if (is_string($initials) && $initials !== '') {
                return $initials;
            }
        }

        return Str::of($this->label($contact))
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $segment): string => Str::upper(Str::substr($segment, 0, 1)))
            ->implode('');
    }

    public function resolveByPhone(string $phone): ?Model
    {
        return $this->contacts()
            ->first(fn (Model $contact): bool => $this->phonesMatch($this->phone($contact), $phone));
    }

    /**
     * @param  Collection<int, Model>|EloquentCollection<int, Model>  $contacts
     * @return Collection<int, Model>
     */
    public function search(Collection|EloquentCollection $contacts, string $search): Collection
    {
        if ($search === '') {
            return collect($contacts->all());
        }

        $needle = Str::lower($search);
        $digitsNeedle = $this->digits($needle);

        return collect($contacts->all())->filter(function (Model $contact) use ($needle, $digitsNeedle): bool {
            foreach ($this->searchAttributes() as $attribute) {
                $value = data_get($contact, $attribute);

                if (is_scalar($value) && str_contains(Str::lower((string) $value), $needle)) {
                    return true;
                }
            }

            return $digitsNeedle !== '' && str_contains($this->digits($this->phone($contact)), $digitsNeedle);
        })->values();
    }

    public function phonesMatch(string $storedPhone, string $incomingPhone): bool
    {
        $storedDigits = $this->digits($storedPhone);
        $incomingDigits = $this->digits($incomingPhone);

        if ($storedDigits === '' || $incomingDigits === '') {
            return false;
        }

        return $storedDigits === $incomingDigits
            || str_ends_with($incomingDigits, ltrim($storedDigits, '0'))
            || str_ends_with($storedDigits, ltrim($incomingDigits, '0'));
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }
}
