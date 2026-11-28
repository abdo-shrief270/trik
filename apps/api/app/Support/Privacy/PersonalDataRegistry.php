<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * The modules that hold personal data. Order doesn't matter: a request first expands the subject
 * through every source, then exports or redacts. Holds class names, resolved per call with app(),
 * so no request-scoped state is captured (Octane).
 */
final class PersonalDataRegistry
{
    /** @var list<class-string<PersonalDataSource>> */
    private array $sources = [];

    /** @param class-string<PersonalDataSource> $source */
    public function register(string $source): void
    {
        if (! in_array($source, $this->sources, true)) {
            $this->sources[] = $source;
        }
    }

    /** @return list<PersonalDataSource> */
    public function sources(): array
    {
        return array_map(fn (string $class): PersonalDataSource => app($class), $this->sources);
    }
}
