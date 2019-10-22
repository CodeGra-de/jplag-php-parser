#!/usr/bin/env php
<?php

set_error_handler(function ($errNo, $errStr, $errFile, $errLine) {
    $msg = "$errStr in $errFile on line $errLine";
    throw new Error($msg, $errNo);
}, E_ALL);

// Autoload required classes
require __DIR__ . "/vendor/autoload.php";

use Microsoft\PhpParser\DiagnosticsProvider;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\FilePositionMap;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\TokenKind;
use Microsoft\PhpParser\Token\MissingToken;
use Microsoft\PhpParser\Node\Statement;
use Microsoft\PhpParser\Node\Expression;
use MyCLabs\Enum\Enum;

class Token extends Enum
{
    // We cannot use value 1, as this is reserverd for FILE_END
    const FOR_BEGIN = 47;
    const FOR_END = 2;
    const BREAK = 3;
    const CONTINUE = 4;
    const CLASS_BEGIN = 5;
    const CLASS_END = 6;
    const DO_BEGIN = 7;
    const DO_END = 8;
    const FUNCTION_BEGIN = 9;
    const FUNCTION_END = 10;
    const VARDEF = 11;
    const IF_BEGIN = 12;
    const IF_END = 13;
    const ELSE = 14;
    const GOTO = 15;
    const INLINE_HTML = 16;
    const INTERFACE_BEGIN = 17;
    const INTERFACE_END = 18;
    const NAMESPACE = 19;
    const NAMESPACE_USE = 20;
    const RETURN = 21;
    const SWITCH_BEGIN = 22;
    const SWITCH_END = 23;
    const THROW = 24;
    const TRAIT_BEGIN = 25;
    const TRAIT_END = 26;
    const TRY = 27;
    const CATCH_BEGIN = 28;
    const CATCH_END = 29;
    const FINALLY = 30;
    const WHILE_BEGIN = 31;
    const WHILE_END = 32;
    const CASE = 33;
    const TRAIT_USE = 34;
    const ASSIGN = 35;
    const TERNARY = 36; // COND expression
    const NEW_CLASS = 37;
    const IN_CLASS_BEGIN = 38;
    const IN_CLASS_END = 39;
    const NEW_ARRAY = 40;
    const APPLY = 41;  // CALL expression
    const ECHO = 42;
    const UNSET = 43;
    const ISSET = 44;
    const EVAL = 45;
    const YIELD = 46;
}

if (count($argv) < 2) {
    fwrite(STDERR, "Usage: " . $argv[0] . " FILE_TO_PARSE\n");
    exit(1);
}

if (count($argv) > 2 && $argv[2] == 'AMOUNT') {
    echo count(Token::values()) + 1 . "\n";
    exit(0);
}
if (count($argv) > 2 && $argv[2] == 'MAPPING') {
    $res = array();
    foreach (Token::values() as $value) {
        $res[$value->getValue()] = $value->getKey();
    }
    echo json_encode($res);
    exit(0);
}

$inputFileName = $argv[1];
if ($inputFileName === "/dev/stdin") {
    $inputFileName = "php://stdin";
}
$file = file_get_contents($inputFileName);
$lookup = new FilePositionMap($file);
$parser = new Parser(); # instantiates a new parser instance
$astNode = $parser->parseSourceFile($file); # returns an AST from string contents
$errors =  DiagnosticsProvider::getDiagnostics($astNode); # get errors from AST Node (as a Generator)

fwrite(STDERR, json_encode($errors) . "\n");

$res = array();

function emit($tok, $token) {
    global $lookup, $res;

    $start = $lookup->getStartLineCharacterPositionForOffset($token);

    array_push($res, array(
        "token" => array(
            "key" => $tok->getKey(),
            "value" => $tok->getValue(),
        ),
        "line" => $start->line,
        "column" => $start->character,
        "length" => ($token instanceof Node) ? $token->getWidth() : $token->length,
    ));
}

function emitEnd($tok, $node) {
    global $lookup, $res;

    $end = $lookup->getEndLineCharacterPosition($node);

    array_push($res, array(
        "token" => array(
            "key" => $tok->getKey(),
            "value" => $tok->getValue(),
        ),
        "line" => $end->line,
        "column" => $end->character - 1,
        "length" => 1,
    ));
}

function match(Node $node) {
    global $lookup;

    $didTrav = false;
    $trav = function() use (&$didTrav, $node) {
        $didTrav = true;
        traverse($node);
    };

    if ($node instanceof Statement\ForStatement || $node instanceof Statement\ForeachStatement) {
        if ($node instanceof Statement\ForStatement) {
            emit(Token::FOR_BEGIN(), $node->for);
        } else {
            emit(Token::FOR_BEGIN(), $node->foreach);
        }
        $trav();
        emitEnd(Token::FOR_END(), $node);
    } elseif ($node instanceof Statement\BreakOrContinueStatement) {
        $kind = $node->breakOrContinueKeyword->kind;
        $tok = NULL;
        if ($kind == TokenKind::BreakKeyword) {
            $tok = Token::BREAK();
        } elseif ($kind == TokenKind::ContinueKeyword) {
            $tok = Token::CONTINUE();
        }
        if ($tok != NULL) {
            emit($tok, $node->breakOrContinueKeyword);
        }
    } elseif ($node instanceof Statement\ClassDeclaration) {
        emit(Token::CLASS_BEGIN(), $node->classKeyword);
        $trav();
        emitEnd(Token::CLASS_END(), $node);
    } elseif ($node instanceof Statement\DoStatement) {
        emit(Token::DO_BEGIN(), $node->do);
        $trav();
        emitEnd(Token::DO_END(), $node);
    } elseif ($node instanceof Statement\FunctionDeclaration ||
              $node instanceof Node\MethodDeclaration ||
              $node instanceof Expression\AnonymousFunctionCreationExpression
    ) {
        emit(Token::FUNCTION_BEGIN(), $node->functionKeyword);
        $trav();
        emitEnd(Token::FUNCTION_END(), $node);
    } elseif ($node instanceof Node\StaticVariableDeclaration) {
        emit(Token:: VARDEF(), $node->variableName);
    } elseif ($node instanceof Statement\IfStatementNode) {
        emit(Token::IF_BEGIN(), $node->ifKeyword);
        $trav();
        foreach ($node->elseIfClauses as $_) {
            emitEnd(Token::IF_END(), $node);
        }
        emitEnd(Token::IF_END(), $node);
    } elseif ($node instanceof Statement\GotoStatement) {
        emit(Token::GOTO(), $node->goto);
    } elseif ($node instanceof Statement\InlineHtml) {
        // Let this emit an echo only.
        if (($node->scriptSectionStartTag->kind ?? null) === TokenKind::ScriptSectionStartWithEchoTag) {
            traverse($node);
            return;
        }
        $loc = $lookup->getStartLineCharacterPositionForOffset($node->scriptSectionStartTag ?? $node);

        if ($loc->line != 1 || $loc->character != 1) {
            emit(Token::ECHO(), $node->scriptSectionStartTag ?? $node);
        }

    } elseif ($node instanceof Statement\InterfaceDeclaration) {
        emit(Token::INTERFACE_BEGIN(), $node->interfaceKeyword);
        $trav();
        emitEnd(Token::INTERFACE_END(), $node);
    } elseif ($node instanceof Statement\NamespaceDefinition) {
        emit(Token::NAMESPACE(), $node->namespaceKeyword);
    } elseif ($node instanceof Statement\NamespaceUseDeclaration) {
        emit(Token::NAMESPACE_USE(), $node->useKeyword);
    } elseif ($node instanceof Statement\ReturnStatement) {
        emit(Token::RETURN(), $node->returnKeyword);
    } elseif ($node instanceof Statement\SwitchStatementNode) {
        emit(Token::SWITCH_BEGIN(), $node->switchKeyword);
        $trav();
        emitEnd(Token::SWITCH_END(), $node);
    } elseif ($node instanceof Statement\ThrowStatement) {
        emit(Token::THROW(), $node->throwKeyword);
    } elseif ($node instanceof Statement\TraitDeclaration) {
        emit(Token::TRAIT_BEGIN(), $node->traitKeyword);
        $trav();
        emitEnd(Token::TRAIT_END(), $node);
    } elseif ($node instanceof Statement\TryStatement) {
        emit(Token::TRY(), $node->tryKeyword);
    } elseif ($node instanceof Statement\WhileStatement) {
        emit(Token::WHILE_BEGIN(), $node->whileToken);
        $trav();
        emitEnd(Token::WHILE_END(), $node);
    } elseif ($node instanceof Node\ElseClauseNode) {
        emit(Token::ELSE(), $node->elseKeyword);
    } else if ($node instanceof Node\ElseIfClauseNode) {
        // We can use `else if` and `elseif` in php. We want to emit the
        // same tokens for those, so we emulate a `else if` if we see a
        // `elseif`.
        emit(Token::ELSE(), $node->elseIfKeyword);
        emit(Token::IF_BEGIN(), $node->elseIfKeyword);
        $trav();
    } elseif ($node instanceof Node\CaseStatementNode) {
        emit(Token::CASE(), $node->caseKeyword);
    } elseif ($node instanceof Node\CatchClause) {
        emit(Token::CATCH_BEGIN(), $node->catch);
        $trav();
        emitEnd(Token::CATCH_END(), $node);
    } elseif ($node instanceof Node\DefaultStatementNode) {
        emit(Token::CASE(), $node->defaultKeyword);
    } elseif ($node instanceof Node\ConstElement) {
        emit(Token::VARDEF(), $node->name);
    } elseif ($node instanceof Node\FinallyClause) {
        emit(Token::FINALLY(), $node->finallyToken);
    } elseif ($node instanceof Node\PropertyDeclaration) {
        foreach ($node->propertyElements->getElements() as $el) {
            emit(Token::VARDEF(), $el);
            match($el);
        }
        $didTrav = true;
    } elseif ($node instanceof Node\TraitUseClause) {
        foreach ($node->traitNameList->getElements() as $el) {
            emit(Token::TRAIT_USE(), $el);
            match($el);
        }
        if ($node->traitSelectAndAliasClauses != NULL) {
            traverse($node->traitSelectAndAliasClauses);
        }
        $didTrav = true;
    } elseif ($node instanceof Expression\AssignmentExpression) {
        if (!($node->leftOperand instanceof Expression\ListIntrinsicExpression)) {
            emit(Token::ASSIGN(), $node->operator);
        }
    } elseif ($node instanceof Expression\TernaryExpression) {
        emit(Token::TERNARY(), $node->questionToken);
    } elseif ($node instanceof Expression\ObjectCreationExpression) {
        // Yes, really...
        emit(Token::NEW_CLASS(), $node->newKeword);

        if (($node->classTypeDesignator->kind ?? NULL) == TokenKind::ClassKeyword) {
            emit(Token::IN_CLASS_BEGIN(), $node->classTypeDesignator);
            $trav();
            emitEnd(Token::IN_CLASS_END(), $node);
        }
    } elseif ($node instanceof Expression\ArrayCreationExpression) {
        emit(Token::NEW_ARRAY(), $node->arrayKeyword ?? $node->openParenOrBracket);
    } elseif ($node instanceof Expression\CallExpression) {
        emit(Token::APPLY(), $node->callableExpression);
    } elseif ($node instanceof Expression\EchoExpression) {
        emit(Token::ECHO(), $node->echoKeyword ?? $node);
        if ($node->expressions instanceof MissingToken) {
            echo $node->expressions->kind;
            match($node->expressions);
        }
        $didTrav = true;
    } elseif ($node instanceof Expression\PrintIntrinsicExpression) {
        emit(Token::ECHO(), $node->printKeyword);
    } elseif ($node instanceof Expression\UnsetIntrinsicExpression) {
        emit(Token::UNSET(), $node->unsetKeyword);
    } elseif ($node instanceof Expression\PostfixUpdateExpression) {
        emit(Token::ASSIGN(), $node->incrementOrDecrementOperator);
    } elseif ($node instanceof Expression\IssetIntrinsicExpression) {
        emit(Token::ISSET(), $node->issetKeyword);
    } elseif ($node instanceof Expression\ExitIntrinsicExpression ||
               $node instanceof Expression\EmptyIntrinsicExpression ||
               $node instanceof Expression\CloneExpression
    ) {
        emit(Token::APPLY(), $node);
    } elseif ($node instanceof Expression\EvalIntrinsicExpression) {
        emit(Token::EVAL(), $node->evalKeyword);
    } elseif ($node instanceof Expression\ListIntrinsicExpression) {
        if ($node->listElements) {
            foreach ($node->listElements->getElements() as $el) {
                if (!(($el->elementValue ?? NULL) instanceof Expression\ListIntrinsicExpression)) {
                    emit(Token::ASSIGN(), $el);
                }
                traverse($el);
            }
        }
        $didTrav = true;
    } elseif ($node instanceof Expression\YieldExpression) {
        emit(Token::YIELD(), $node->yieldOrYieldFromKeyword);
    }

    if (!$didTrav) {
        $trav();
    }
}

function traverse(Node $root) {
    foreach ($root->getChildNodes() as $node) {
        match($node);
    }
}

traverse($astNode);
echo json_encode($res);
