<?php
/**
 * NTDST Core facade stubs: container map + REST route recorder. Autowiring is NOT emulated:
 * tests register collaborators explicitly via TestCase::set().
 */
declare(strict_types=1);

$GLOBALS['cv_ntdst'] = ['container' => [], 'routes' => []];

function ntdst_get(string $id): mixed
{
    if (!array_key_exists($id, $GLOBALS['cv_ntdst']['container'])) {
        throw new RuntimeException("Service not registered in test container: {$id}");
    }
    $value = $GLOBALS['cv_ntdst']['container'][$id];
    return $value instanceof Closure ? $value() : $value;
}

function ntdst_set(string $id, mixed $value = null): void
{
    $GLOBALS['cv_ntdst']['container'][$id] = $value;
}

final class NtdstRestStub
{
    public function __construct(private readonly string $namespace)
    {
    }

    public function __call(string $verb, array $args): self
    {
        [$route, $callback, $options] = [$args[0], $args[1], $args[2] ?? []];
        $GLOBALS['cv_ntdst']['routes'][] = [
            'ns' => $this->namespace, 'verb' => strtoupper($verb), 'route' => $route, 'callback' => $callback, 'options' => $options, 'public' => false,
        ];
        return $this;
    }

    public function public(): self
    {
        $last = array_key_last($GLOBALS['cv_ntdst']['routes']);
        $GLOBALS['cv_ntdst']['routes'][$last]['public'] = true;
        return $this;
    }
}

function ntdst_rest(string $namespace): NtdstRestStub
{
    return new NtdstRestStub($namespace);
}

function ntdst_log(string $channel): object
{
    return new class {
        public function __call(string $level, array $args): void
        {
            $GLOBALS['cv_test']['log'][] = [$level, $args[0] ?? '', $args[1] ?? []];
        }
    };
}
