<?php
declare(strict_types=1);

namespace Wordless\Mcp;

/**
 * Erreur de protocole JSON-RPC (méthode inconnue, paramètres invalides…).
 * Les erreurs d'exécution d'un outil passent par ToolError (résultat isError).
 */
final class RpcError extends \Exception
{
    public function __construct(int $code, string $message, public readonly mixed $data = null)
    {
        parent::__construct($message, $code);
    }
}
