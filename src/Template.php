<?php
/**
 * SharkyPHP Template Library
 *
 * 微模版引擎
 *
 * @package SharkyPHP\\Template
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Template;

use Sharky\Template\Exceptions\ViewsBuildException;

final class Template
{

    public function __construct(
        private string $template,
        private array $variables = [],
        private array $functions = []
    ) {
    }

    public function __toString(): string
    {
        unset($this->variables['this']);
        extract($this->variables, EXTR_OVERWRITE);
        ob_start(null, 4 * 1024 * 1024);
        include $this->template;
        return ob_get_clean();
    }

    public function call(string $name, ...$args)
    {
        if (! isset($this->functions[$name])) {
            throw new ViewsBuildException(sprintf("Template function '%s' is not registered", $name));

        }
        return $this->functions[$name](...$args);
    }

}
