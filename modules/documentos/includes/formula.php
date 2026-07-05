<?php
/**
 * Avaliador seguro de fórmulas matemáticas.
 *
 * Suporta: números decimais, operadores + - * / ^, parênteses,
 *          variáveis alfanuméricas e funções: abs, min, max, round, sqrt.
 *
 * Implementação: tokenização → shunting-yard (infix → RPN) → avaliação.
 * NÃO usa eval() e bloqueia caracteres desconhecidos.
 *
 * Uso:
 *   formula_evaluate('(a/b)*100', ['a' => 5, 'b' => 20]);  // 25.0
 *   formula_validate('(a+b)*c', ['a', 'b', 'c']);           // null ou string de erro
 */

/**
 * Avalia a fórmula com os valores das variáveis.
 * @throws InvalidArgumentException em caso de erro sintático ou variável ausente
 */
function formula_evaluate($expression, array $vars) {
    $tokens = _formula_tokenize($expression, $vars);
    $rpn    = _formula_to_rpn($tokens);
    return _formula_eval_rpn($rpn);
}

/**
 * Valida uma fórmula dada a lista de variáveis permitidas.
 * Retorna null se OK, ou string com a mensagem de erro.
 */
function formula_validate($expression, array $allowed_vars) {
    if (trim($expression) === '') return null; // fórmula opcional
    try {
        $dummy = [];
        foreach ($allowed_vars as $v) $dummy[$v] = 1;
        // Substitui variáveis desconhecidas por zero para teste? Não —
        // preferimos falhar com variável não declarada.
        $tokens = _formula_tokenize($expression, $dummy);
        $rpn    = _formula_to_rpn($tokens);
        _formula_eval_rpn($rpn);
        return null;
    } catch (Throwable $ex) {
        return $ex->getMessage();
    }
}

/**
 * Extrai os nomes de variáveis referenciados na fórmula.
 * Ignora nomes de funções.
 */
function formula_extract_variables($expression) {
    $functions = ['abs', 'min', 'max', 'round', 'sqrt'];
    $vars = [];
    if (preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $expression, $m)) {
        foreach ($m[0] as $name) {
            if (!in_array($name, $functions, true)) {
                $vars[$name] = true;
            }
        }
    }
    return array_keys($vars);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Internos
// ═══════════════════════════════════════════════════════════════════════════

function _formula_tokenize($expression, array $vars) {
    // Converte vírgulas decimais (ex.: 0,5) para pontos, MANTENDO a vírgula
    // de separação de argumentos (ex.: max(a, b)). Regra: vírgula entre
    // dígitos ou após ponto → é decimal.
    $expression = preg_replace('/(\d),(\d)/', '$1.$2', trim((string) $expression));
    $len    = strlen($expression);
    $tokens = [];
    $i      = 0;
    $functions = ['abs' => 1, 'min' => 2, 'max' => 2, 'round' => 1, 'sqrt' => 1];

    while ($i < $len) {
        $ch = $expression[$i];

        if (ctype_space($ch)) { $i++; continue; }

        // Número
        if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($expression[$i + 1]))) {
            $num = '';
            while ($i < $len && (ctype_digit($expression[$i]) || $expression[$i] === '.')) {
                $num .= $expression[$i++];
            }
            $tokens[] = ['type' => 'num', 'value' => (float) $num];
            continue;
        }

        // Identificador (variável ou função)
        if (ctype_alpha($ch) || $ch === '_') {
            $id = '';
            while ($i < $len && (ctype_alnum($expression[$i]) || $expression[$i] === '_')) {
                $id .= $expression[$i++];
            }
            if (isset($functions[$id])) {
                $tokens[] = ['type' => 'fn', 'value' => $id, 'argc' => $functions[$id]];
            } else {
                if (!array_key_exists($id, $vars)) {
                    throw new InvalidArgumentException("Variável '$id' não definida.");
                }
                $tokens[] = ['type' => 'num', 'value' => (float) $vars[$id]];
            }
            continue;
        }

        // Operadores e separadores
        if (strpos('+-*/^(),', $ch) !== false) {
            $tokens[] = ['type' => 'op', 'value' => $ch];
            $i++;
            continue;
        }

        throw new InvalidArgumentException("Caractere inválido na fórmula: '$ch'");
    }
    return $tokens;
}

function _formula_to_rpn(array $tokens) {
    $prec   = ['+' => 1, '-' => 1, '*' => 2, '/' => 2, '^' => 3];
    $rassoc = ['^' => true];
    $output = [];
    $stack  = [];
    $prev   = null;

    foreach ($tokens as $tok) {
        if ($tok['type'] === 'num') {
            $output[] = $tok;
        } elseif ($tok['type'] === 'fn') {
            $stack[] = $tok;
        } elseif ($tok['type'] === 'op' && $tok['value'] === ',') {
            while (!empty($stack) && end($stack)['value'] !== '(') {
                $output[] = array_pop($stack);
            }
            if (empty($stack)) throw new InvalidArgumentException("Vírgula fora de função.");
        } elseif ($tok['type'] === 'op' && in_array($tok['value'], ['+', '-', '*', '/', '^'], true)) {
            // Detecta operador unário (-x, +x)
            $is_unary = ($prev === null)
                || ($prev['type'] === 'op' && in_array($prev['value'], ['+', '-', '*', '/', '^', '(', ','], true));
            if ($is_unary && ($tok['value'] === '-' || $tok['value'] === '+')) {
                if ($tok['value'] === '-') {
                    // Implementa negação como (0 - x)
                    $output[] = ['type' => 'num', 'value' => 0];
                    $stack[]  = ['type' => 'op', 'value' => '-', 'unary' => true];
                }
                // '+' unário é no-op
                $prev = $tok;
                continue;
            }
            $op = $tok['value'];
            while (!empty($stack)) {
                $top = end($stack);
                if ($top['type'] === 'op' && $top['value'] !== '('
                    && isset($prec[$top['value']])
                    && ( ($prec[$top['value']] > $prec[$op])
                       || ($prec[$top['value']] === $prec[$op] && empty($rassoc[$op])) )) {
                    $output[] = array_pop($stack);
                } elseif ($top['type'] === 'fn') {
                    $output[] = array_pop($stack);
                } else {
                    break;
                }
            }
            $stack[] = $tok;
        } elseif ($tok['value'] === '(') {
            $stack[] = $tok;
        } elseif ($tok['value'] === ')') {
            while (!empty($stack) && end($stack)['value'] !== '(') {
                $output[] = array_pop($stack);
            }
            if (empty($stack)) throw new InvalidArgumentException("Parênteses desbalanceados.");
            array_pop($stack); // descarta '('
            if (!empty($stack) && end($stack)['type'] === 'fn') {
                $output[] = array_pop($stack);
            }
        }
        $prev = $tok;
    }
    while (!empty($stack)) {
        $top = array_pop($stack);
        if ($top['value'] === '(' || $top['value'] === ')') {
            throw new InvalidArgumentException("Parênteses desbalanceados.");
        }
        $output[] = $top;
    }
    return $output;
}

function _formula_eval_rpn(array $rpn) {
    $stack = [];
    foreach ($rpn as $tok) {
        if ($tok['type'] === 'num') {
            $stack[] = $tok['value'];
        } elseif ($tok['type'] === 'op') {
            if (count($stack) < 2) throw new InvalidArgumentException("Expressão inválida.");
            $b = array_pop($stack);
            $a = array_pop($stack);
            switch ($tok['value']) {
                case '+': $stack[] = $a + $b; break;
                case '-': $stack[] = $a - $b; break;
                case '*': $stack[] = $a * $b; break;
                case '/':
                    if ($b == 0) throw new InvalidArgumentException("Divisão por zero.");
                    $stack[] = $a / $b; break;
                case '^': $stack[] = pow($a, $b); break;
            }
        } elseif ($tok['type'] === 'fn') {
            $argc = $tok['argc'];
            if (count($stack) < $argc) throw new InvalidArgumentException("Argumentos insuficientes para {$tok['value']}().");
            $args = [];
            for ($k = 0; $k < $argc; $k++) array_unshift($args, array_pop($stack));
            switch ($tok['value']) {
                case 'abs':   $stack[] = abs($args[0]); break;
                case 'sqrt':  $stack[] = sqrt($args[0]); break;
                case 'round': $stack[] = round($args[0]); break;
                case 'min':   $stack[] = min($args[0], $args[1]); break;
                case 'max':   $stack[] = max($args[0], $args[1]); break;
            }
        }
    }
    if (count($stack) !== 1) throw new InvalidArgumentException("Expressão inválida.");
    return (float) $stack[0];
}
