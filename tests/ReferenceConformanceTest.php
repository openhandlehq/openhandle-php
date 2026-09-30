<?php

declare(strict_types=1);

namespace OpenHandle\Tests;

use OpenHandle\Internal\References;
use OpenHandle\OpenHandleReferenceError;
use OpenHandle\ReferenceMismatchError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceConformanceTest extends TestCase
{
    public function testFixtureVersionIsPinned(): void
    {
        self::assertSame(1, self::fixture()['version']);
    }

    /**
     * @param array{kind: string, value: string} $input
     */
    #[DataProvider('cases')]
    public function testSharedReferenceConformance(
        string $platform,
        string $resource,
        array $input,
        ?string $identifier,
        ?string $error,
    ): void {
        if ($error === null) {
            self::assertSame($identifier, References::resolve($input['kind'], $input['value'], $platform, $resource));

            return;
        }

        try {
            References::resolve($input['kind'], $input['value'], $platform, $resource);
            self::fail("The reference resolved instead of failing with {$error}.");
        } catch (OpenHandleReferenceError $raised) {
            self::assertSame($error, $raised instanceof ReferenceMismatchError ? 'reference_mismatch' : 'invalid_reference');
        }
    }

    /**
     * @return iterable<string, array{string, string, array{kind: string, value: string}, string|null, string|null}>
     */
    public static function cases(): iterable
    {
        foreach (self::fixture()['cases'] as $case) {
            yield $case['name'] => [
                $case['platform'],
                $case['resource'],
                $case['input'],
                $case['identifier'] ?? null,
                $case['error'] ?? null,
            ];
        }
    }

    /**
     * @return array{version: int, cases: list<array{name: string, platform: string, resource: string, input: array{kind: string, value: string}, identifier?: string, error?: string}>}
     */
    private static function fixture(): array
    {
        /** @var array{version: int, cases: list<array{name: string, platform: string, resource: string, input: array{kind: string, value: string}, identifier?: string, error?: string}>} */
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/reference-conformance.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
