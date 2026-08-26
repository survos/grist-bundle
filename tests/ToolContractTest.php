<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The agent/MCP surface is a contract: tool names are what a model calls, and a
 * rename or a missing attribute breaks callers silently rather than loudly. These
 * assertions are cheap and catch exactly that class of mistake.
 */
final class ToolContractTest extends TestCase
{
    private const string NAMESPACE = 'Survos\\GristBundle\\Tool\\';

    /** @return list<class-string> */
    private static function toolClasses(): array
    {
        $classes = [];
        foreach (glob(dirname(__DIR__).'/src/Tool/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            if (!str_ends_with($short, 'Tool')) {
                continue; // ToolResponse is a helper, not a tool
            }
            $classes[] = self::NAMESPACE.$short;
        }
        sort($classes);

        return $classes;
    }

    /** @return iterable<string,array{class-string}> */
    public static function tools(): iterable
    {
        foreach (self::toolClasses() as $class) {
            yield substr($class, strlen(self::NAMESPACE)) => [$class];
        }
    }

    public function testEveryToolIsDiscovered(): void
    {
        self::assertNotEmpty(self::toolClasses(), 'No tool classes found in src/Tool.');
    }

    #[DataProvider('tools')]
    public function testToolCarriesBothAttributes(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        self::assertNotEmpty($reflection->getAttributes(AsTool::class), $class.' is missing #[AsTool].');
        self::assertNotEmpty($reflection->getAttributes(McpTool::class), $class.' is missing #[McpTool].');
    }

    #[DataProvider('tools')]
    public function testAgentAndMcpNamesAgree(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $asTool = $reflection->getAttributes(AsTool::class)[0]->newInstance();
        $mcpTool = $reflection->getAttributes(McpTool::class)[0]->newInstance();

        self::assertSame(
            $asTool->name,
            $mcpTool->name,
            $class.': the agent tool name and the MCP tool name must match, or the same capability is exposed under two identities.',
        );
    }

    #[DataProvider('tools')]
    public function testToolNameIsNamespacedSnakeCase(string $class): void
    {
        $name = (new \ReflectionClass($class))->getAttributes(AsTool::class)[0]->newInstance()->name;

        self::assertMatchesRegularExpression(
            '/^grist_[a-z0-9]+(_[a-z0-9]+)*$/',
            (string) $name,
            $class.': tool names are a shared namespace across every installed bundle, so they must be prefixed and snake_case.',
        );
    }

    #[DataProvider('tools')]
    public function testDescriptionTellsTheModelWhenToUseIt(string $class): void
    {
        $description = (string) (new \ReflectionClass($class))->getAttributes(AsTool::class)[0]->newInstance()->description;

        self::assertGreaterThan(
            40,
            mb_strlen($description),
            $class.': a one-word description gives a model nothing to select on.',
        );
        self::assertStringEndsWith('.', $description, $class.': descriptions are sentences.');
    }

    /**
     * MCP builds each tool's input schema from docblocks, so an array parameter with
     * no @param is advertised to the model as an untyped array -- it then has to guess
     * the element shape. Typed scalars need no docblock; arrays do.
     *
     * Written as one sweep rather than a data-provided case so it always asserts:
     * scalar-only tools would otherwise make the test risky, and failOnRisky is on.
     */
    public function testArrayParametersAreDocumented(): void
    {
        $undocumented = [];
        foreach (self::toolClasses() as $class) {
            $method = (new \ReflectionClass($class))->getMethod('__invoke');
            $doc = (string) $method->getDocComment();
            foreach ($method->getParameters() as $parameter) {
                if ('array' !== (string) $parameter->getType()) {
                    continue;
                }
                $pattern = '/@param\s+\S+\s+\$'.preg_quote($parameter->getName(), '/').'\b/';
                if (1 !== preg_match($pattern, $doc)) {
                    $undocumented[] = sprintf('%s::__invoke($%s)', $class, $parameter->getName());
                }
            }
        }

        self::assertSame([], $undocumented, 'Array parameters without @param; the model cannot infer their shape.');
    }

    public function testToolNamesAreUnique(): void
    {
        $names = array_map(
            static fn (string $c): ?string => (new \ReflectionClass($c))->getAttributes(AsTool::class)[0]->newInstance()->name,
            self::toolClasses(),
        );

        self::assertSame(array_unique($names), $names, 'Two tools share a name.');
    }

    #[DataProvider('tools')]
    public function testToolIsInvokableAndReturnsAString(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        self::assertTrue($reflection->hasMethod('__invoke'), $class.' must be invokable.');
        self::assertSame(
            'string',
            (string) $reflection->getMethod('__invoke')->getReturnType(),
            $class.': tools return a serialized string.',
        );
    }
}
