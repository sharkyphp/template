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
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Template;

use RuntimeException;
use Sharky\Template\Exceptions\ViewsBuildException;

final class TemplateEngine
{
    protected bool $debug       = false;
    protected bool $compress    = false;
    protected array $blocks     = [];
    protected array $functions  = [];
    protected string $viewsPath = "";
    protected string $cachePath = "";
    protected string $prefix    = "";

    public function __construct(array $config = [])
    {
        $this->compress  = $config['compress'] ?? false;
        $this->debug     = $config['debug'] ?? false;
        $this->viewsPath = $config['viewsPath'] ?? false;
        $this->cachePath = $config['cachePath'] ?? false;

        if (! is_dir($this->viewsPath)) {
            throw new RuntimeException(sprintf("Template views directory [%s] does not exist.", $this->viewsPath));
        }
        if (! is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0755, true);
            // throw new RuntimeException(sprintf("Template cache directory [%s] does not exist.", $this->cachePath));
        }

    }

    // 渲染模板
    public function render(string $template, array $variables = []): Template
    {
        $template = preg_replace('/[\\/]+/', DIRECTORY_SEPARATOR, $template);
        $paths    = explode(DIRECTORY_SEPARATOR, $template);

        $template     = array_pop($paths);
        $this->prefix = implode(DIRECTORY_SEPARATOR, $paths);

        $compile = $this->compile($template);

        return new Template(
            $compile,
            $variables,
            $this->functions
        );
    }

    // 添加模板函数
    public function addFunction(string $name, callable $callback): void
    {
        $this->functions[$name] = $callback;
    }

    // 编译模板：主程序
    private function compile(string $template, bool $isParse = true, ?string $dir = null): string
    {
        // 当前模板所在子目录（继承 / include 递归时由调用方传入）
        $dir = $dir ?? $this->prefix;

        // 定位模板文件：先按当前目录解析，找不到再从视图根目录解析
        [$viewsFile, $dirUsed] = $this->locateView($template, $dir);

        $cachePath = $this->cachePath . ($dirUsed !== '' ? DIRECTORY_SEPARATOR . $dirUsed : '');
        $cacheFile = $cachePath . DIRECTORY_SEPARATOR . md5($viewsFile) . '.php';
        $viewsFile = preg_replace('/[\\/]+/', DIRECTORY_SEPARATOR, $viewsFile);
        $cacheFile = preg_replace('/[\\/]+/', DIRECTORY_SEPARATOR, $cacheFile);

        $viewsTime = is_readable($viewsFile) ? (int) filemtime($viewsFile) : 0;

        if ($this->debug ||
            ! file_exists($cacheFile) ||
            filemtime($cacheFile) < $viewsTime
        ) {

            if (! is_readable($viewsFile)) {
                throw new ViewsBuildException(
                    sprintf("Template file '%s' does not exist", $viewsFile)
                );
            }

            $content = file_get_contents($viewsFile);
            // 处理模版继承
            $content = $this->parseExtends($content, $dirUsed);
            // 解析模板
            if ($isParse) {
                $content = $this->parseTemplate($content, $dirUsed);
            }

            if ($this->debug) {
                $cacheFile = $cachePath . '/debug/' . md5($template) . '.php';
            }
            // 创建缓存路径
            $cachePath = dirname($cacheFile);
            if (! is_dir($cachePath)) {
                @mkdir($cachePath, 0755, true);
            }

            file_put_contents($cacheFile, $content);
        }

        return $cacheFile;
    }

    /**
     * 定位视图文件
     *
     * 支持两种写法（均相对于 viewsPath）：
     *  - 相对当前模板所在目录："layout.html"
     *  - 相对视图根目录："demo/layout.html"
     *
     * @return array{0: string, 1: string} [绝对路径, 相对视图根目录的子目录]
     */
    private function locateView(string $template, string $dir): array
    {
        $template = trim(
            (string) preg_replace('/[\\\\\\/]+/', DIRECTORY_SEPARATOR, $template),
            DIRECTORY_SEPARATOR
        );

        $candidates = [];

        if ($dir !== '') {
            $candidates[] = [
                $this->viewsPath . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $template,
                $dir,
            ];
        }

        $subDir = dirname($template);

        $candidates[] = [
            $this->viewsPath . DIRECTORY_SEPARATOR . $template,
            $subDir === '.' ? '' : str_replace(DIRECTORY_SEPARATOR, '/', $subDir),
        ];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate[0])) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    // 解析模板
    private function parseTemplate(string $content, string $dir = ''): string
    {
        // 渲染Block
        $content = $this->renderBlocks($content);

        // 引入其他模板
        if (preg_match('/{%\s*include\s*[\'"](.+?)[\'"]\s*%}/', $content, $matches)) {
            $includeTemplate  = $matches[1];
            $that             = clone $this;
            $that->blocks     = [];
            $compiledTemplate = $that->compile($includeTemplate, true, $dir);
            $templateContent  = file_get_contents($compiledTemplate);
            $content          = str_replace($matches[0], $templateContent, $content);
        }

        // 变量/常量输出，支持模板函数（默认转义，防 XSS）
        $content = preg_replace_callback('/{{\s*(.+?)\s*}}/', function ($matches) {
            $expression = trim($matches[1]);
            $expression = $this->parseExpression($expression);
            $expression = trim($expression, ';');
            return "<?php echo htmlspecialchars((string) ({$expression}), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>";
        }, $content);

        // 原始输出，不转义（同样支持模板函数，路由到 $this->call）
        $content = preg_replace_callback('/{!!\s*(.+?)\s*!!}/', function ($matches) {
            $expression = trim($matches[1]);
            $expression = $this->parseExpression($expression);
            $expression = trim($expression, ';');
            return "<?php echo {$expression}; ?>";
        }, $content);

        // 简化控制流指令正则表达式
        $patterns = [
            '/{%\s*if\s+\(?\s*(.+?)\s*\)?\s*%}/'      => '<?php if ($1): ?>',
            '/{%\s*elif\s+\(?\s*(.+?)\s*\)?\s*%}/'    => '<?php elseif ($1): ?>',
            '/{%\s*else\s*%}/'                        => '<?php else: ?>',
            '/{%\s*endif\s*%}/'                       => '<?php endif; ?>',

            '/{%\s*foreach\s+\(?\s*(.+?)\s*\)?\s*%}/' => '<?php foreach ($1): ?>',
            '/{%\s*endforeach\s*%}/'                  => '<?php endforeach; ?>',

            '/{%\s*for\s+\(?\s*(.+?)\s*\)?\s*%}/'     => '<?php for ($1): ?>',
            '/{%\s*endfor\s*%}/'                      => '<?php endfor; ?>',

            '/{%\s*while\s+\(?\s*(.+?)\s*\)?\s*%}/'   => '<?php while ($1): ?>',
            '/{%\s*endwhile\s*%}/'                    => '<?php endwhile; ?>',

            '/{%\s*break\s*%}/'                       => '<?php break; ?>',
            '/{%\s*continue\s*%}/'                    => '<?php continue; ?>',

            '/{%\s*php\s*%}/'                         => '<?php ',
            '/{%\s*endphp\s*%}/'                      => '?>',

            // 输出变量（安全输出，htmlspecialchars）
            '/{{\s*(.+?)\s*}}/'                       => '<?php echo htmlspecialchars($1, ENT_QUOTES); ?>',

            // 注释（模板注释，编译后直接删掉，不输出到html）
            '/{#[\s\S]*?#}/'                          => '',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content);
        }

        // 压缩模板内容
        if ($this->compress) {
            $content = $this->compressHtml($content);
        }
        return trim($content ?? '');
    }

    // 解析继承
    private function parseExtends(string $content, string $dir = ''): string
    {
        $extendsPattern = '/\s?{%\s*extends\s*[\'"](.+?)[\'"]\s*%}/s';
        if (! preg_match_all($extendsPattern, $content, $allMatches)) {
            return $content;
        }

        if (count($allMatches[0]) > 1) {
            throw new ViewsBuildException(
                sprintf("extends does not support multiple inheritance")
            );
        }

        if (! preg_match('/^' . substr($extendsPattern, 1), $content, $matches)) {
            throw new ViewsBuildException(
                sprintf("No other content is allowed before extends tag")
            );
        } else {
            $parentCompiled = $this->compile($matches[1], false, $dir);
            $parentContent  = file_get_contents($parentCompiled);
            // 处理 block 指令 {% block content %}... {% endblock %}
            $this->parseBlocks($parentContent);

            $content = $this->parseBlocks($content);

            $content = str_replace($matches[0], $parentContent, $content);
            $content = $this->parseExtendsBlocks($content);
        }

        return trim($content ?? '');
    }

    // 解析块
    private function parseBlocks(string $content): string
    {
        $blockPattern = '/{%\s*block\s*(.+?)\s*%}(.*?){%\s*endblock\s*%}/s';
        if (preg_match_all($blockPattern, $content, $blockMatches, PREG_SET_ORDER)) {
            $blocks = [];
            foreach ($blockMatches as $blockMatch) {
                $blockName    = $blockMatch[1];
                $blockContent = $blockMatch[2];
                if (isset($blocks[$blockName])) {
                    throw new ViewsBuildException(sprintf("Block '%s' is redefined", $blockName));
                } else {
                    $blocks[$blockName] = $blockContent;
                }
            }

            $this->blocks = [ ...$this->blocks, ...$blocks];
        }

        $result = preg_replace_callback($blockPattern, function ($matches) {
            $blockName = $matches[1];
            if (isset($this->blocks[$blockName])) {
                return '';
            }
            return $matches[0];
        }, $content);
        return trim($result ?? '');
    }

    // 渲染块
    private function renderBlocks(string $content): string
    {
        $blockPattern = '/{%\s*block\s*(.+?)\s*%}(.*?){%\s*endblock\s*%}/s';
        $result       = preg_replace_callback($blockPattern, function ($matches) {
            $blockName = $matches[1];
            if (isset($this->blocks[$blockName])) {
                return $this->blocks[$blockName];
            }
            return $matches[0];
        }, $content);

        return trim($result ?? '');
    }

    // 替换继承块
    private function parseExtendsBlocks(string $content): string
    {
        $blockPattern = '/{%\s*block\s*(.+?)\s*%}(.*?){%\s*endblock\s*%}/s';
        $result       = preg_replace_callback($blockPattern, function ($matches) {
            $blockName = $matches[1];
            if (isset($this->blocks[$blockName])) {
                return '{% block ' . $blockName . ' %}' . $this->blocks[$blockName] . '{% endblock %}';
            }
            return $matches[0];
        }, $content);

        return trim($result ?? '');
    }

    // 解析模板语句
    private function parseExpression(string $input): string
    {
        $output       = '';
        $buffer       = trim($input);
        $inString     = false;
        $quoteChar    = '';
        $pendingSpace = false;
        $missed       = false;

        while ($buffer !== '') {
            $char = $buffer[0];

            if ($inString) {
                if ($char === $quoteChar) {
                    $inString = false;
                }
                $output       .= $char;
                $buffer        = substr($buffer, 1);
                $pendingSpace  = false;
                continue;
            }

            if ($char === ' ') {
                $pendingSpace = true;
                $buffer       = substr($buffer, 1);
                continue;
            }

            if ($pendingSpace) {
                $output       .= ' ';
                $pendingSpace  = false;
            }

            if ($char === "'" || $char === '"') {
                $inString      = true;
                $quoteChar     = $char;
                $output       .= $char;
                $buffer        = substr($buffer, 1);
                $pendingSpace  = false;
                continue;
            }

            if (preg_match('/^(\$?[a-zA-Z0-9_\x7f-\xff]+)(?=[^\(a-zA-Z0-9_\x7f-\xff ]|$)/', $buffer, $matches)) {
                $missed = false;
                $output = rtrim($output);
                $match  = $matches[1];
                if (is_numeric($match)) {
                    $result = $match;
                } else {
                    // 已带 $ 的写法（$item）原样保留，避免编译成 $$item（可变变量）
                    if (str_starts_with($match, '$')) {
                        $result = $match;
                    } elseif (defined($match)) {
                        $result = $match;
                    } else {
                        $result = "\$$match";
                    }
                }
                $output .= ' ' . $result;
                $buffer = substr($buffer, strlen($matches[1]));
            } elseif (preg_match('/^([a-zA-Z0-9_\x7f-\xff]*)(\((?:[^()]+|(?2))*\))/', $buffer, $matches)) {
                $missed = false;

                $output  = rtrim($output);
                $output .= ' ' . $this->compileFunctionCall($matches[0], $matches);
                $buffer  = substr($buffer, strlen($matches[0]));
            } else {
                if ($missed === false) {
                    $missed  = true;
                    $output  = rtrim($output);
                    $output .= ' ';
                }
                $output .= $char;
                $buffer  = substr($buffer, 1);
            }
        }

        return trim($output);
    }

    // 解析函数调用
    private function compileFunctionCall(string $rawCall, array $matches): string
    {
        $rawCall  = trim($rawCall);
        $funcName = $matches[1];
        $argsBody = substr($matches[2], 1, -1);
        $argList  = $this->splitFunctionArgs($argsBody);

        $compiledArgs = [];
        foreach ($argList as $argItem) {
            $compiledArgs[] = $this->parseExpression(trim($argItem));
        }
        $argStr = implode(', ', $compiledArgs);

        if ($funcName === '') {
            return $argStr;
        }
        return "\$this->call('{$funcName}', {$argStr})";
    }

    // 解析函数参数
    private function splitFunctionArgs(string $body): array
    {
        $result       = [];
        $strLen       = strlen($body);
        $startPos     = 0;
        $bracketDepth = 0;
        $inString     = false;
        $quoteChar    = '';

        for ($i = 0; $i < $strLen; $i++) {
            $char = $body[$i];

            if ($inString) {
                if ($char === $quoteChar) {
                    $inString = false;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $inString  = true;
                $quoteChar = $char;
                continue;
            }

            if ($char === '(') {
                $bracketDepth++;
            }

            if ($char === ')') {
                $bracketDepth--;
            }

            if ($char === ',' && $bracketDepth === 0) {
                $result[] = substr($body, $startPos, $i - $startPos);
                $startPos = $i + 1;
            }
        }

        if ($startPos < $strLen) {
            $result[] = substr($body, $startPos);
        }
        return $result;
    }

    // 压缩模板内容
    public function compressHtml(string $content): string
    {
        $placeholders = [];

        // 保护 pre / textarea / code 整块，原样不动
        $content = preg_replace_callback('/<(pre|textarea|code)(\s.*?)?>(.+?)<\/\1>/s', function ($match) use (&$placeholders) {
            $key                = "\x00__SHARKY_PROTECT_" . count($placeholders) . "__\x00";
            $placeholders[$key] = $match[0];
            return $key;
        }, $content);

        // 处理 script / style：只清注释，标签原样保留
        $content = preg_replace_callback('/<(script|style)(\s.*?)?>(.+?)<\/\1>/s', function ($match) {
            $tagName = $match[1];
            $tagAttr = $match[2] ?? '';
            $inner   = $match[3];

            // 清除单行注释 //
            $inner = preg_replace('/\/\/[^\n]*/', '', $inner);
            // 清除多行注释 /* */
            $inner = preg_replace('/\/\*[\s\S]*?\*\//', '', $inner);

            return "<{$tagName}{$tagAttr}>{$inner}</{$tagName}>";
        }, $content);

        // 模板注释
        $content = preg_replace('/{#\s*(.+?)\s*#}/s', ' ', $content);
        // HTML注释，跳过IE条件注释
        $content = preg_replace('/<!--(?!\[if\s).*?-->/s', '', $content);
        // 压缩空白
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/>\s+</', '><', $content);

        // 还原 pre textarea code
        $content = strtr($content, $placeholders);

        return $content;
    }

}
