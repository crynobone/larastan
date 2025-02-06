<?php

declare(strict_types=1);

namespace Rules;

use Larastan\Larastan\Collectors\UsedEmailViewCollector;
use Larastan\Larastan\Collectors\UsedRouteFacadeViewCollector;
use Larastan\Larastan\Collectors\UsedViewFacadeMakeCollector;
use Larastan\Larastan\Collectors\UsedViewFunctionCollector;
use Larastan\Larastan\Collectors\UsedViewInAnotherViewCollector;
use Larastan\Larastan\Collectors\UsedViewMakeCollector;
use Larastan\Larastan\Rules\UnusedViewsRule;
use Larastan\Larastan\Support\ViewFileHelper;
use Orchestra\Testbench\Foundation\Application as Testbench;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function Orchestra\Testbench\laravel_version_compare;

/** @extends RuleTestCase<UnusedViewsRule> */
class UnusedViewsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        $viewFileHelper = new ViewFileHelper([__DIR__ . '/../application/resources/views'], $this->getFileHelper());

        return new UnusedViewsRule(new UsedViewInAnotherViewCollector(
            $this->getContainer()->getService('currentPhpVersionSimpleDirectParser'),
            $viewFileHelper,
        ), $viewFileHelper);
    }

    /** @return array<Collector<Node, mixed>> */
    protected function getCollectors(): array
    {
        return [
            new UsedViewFunctionCollector(),
            new UsedEmailViewCollector(),
            new UsedViewMakeCollector(),
            new UsedViewFacadeMakeCollector(),
            new UsedRouteFacadeViewCollector(),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // This is a workaround for a weird PHPStan container cache issue.
        require __DIR__ . '/../../bootstrap.php';
    }

    protected function tearDown(): void
    {
        if (laravel_version_compare('11.0', '>=')) {
            Testbench::flushState($this);
        } else {
            Testbench::flushState();
        }

        parent::tearDown();
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/FooController.php'], [
            [
                'This view is not used in the project.',
                00,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../extension.neon',
        ];
    }
}
