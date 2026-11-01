<?php
declare(strict_types=1);

namespace Wordless\Mcp;

/**
 * Échec d'un outil (droits insuffisants, contenu introuvable, argument refusé).
 * Le message est renvoyé au modèle dans un résultat « isError » pour qu'il
 * puisse corriger sa demande : il doit donc être explicite et sans secret.
 */
final class ToolError extends \Exception
{
}
