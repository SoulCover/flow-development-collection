<?php
namespace TYPO3\Fluid\Core\Parser\TemplateProcessor;

/*
 * This file is part of the TYPO3.Fluid package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3Fluid\Fluid\Core\Parser\Patterns;
use TYPO3Fluid\Fluid\Core\Parser\UnknownNamespaceException;

/**
 *
 */
class NamespaceDetectionTemplateProcessor extends \TYPO3Fluid\Fluid\Core\Parser\TemplateProcessor\NamespaceDetectionTemplateProcessor
{
    /**
     * Extension of the default pattern for dynamic tags including namespaces with uppercase letters.
     */
    static public $EXTENDED_SPLIT_PATTERN_TEMPLATE_DYNAMICTAGS = '/
		(
			(?: <\/?                                      # Start dynamic tags
					(?:(?:[a-zA-Z0-9\\.]*):[a-zA-Z0-9\\.]+)  # A tag consists of the namespace prefix and word characters
					(?:                                   # Begin tag arguments
						\s*[a-zA-Z0-9:-]+                 # Argument Keys
						=                                 # =
						(?>                               # either... If we have found an argument, we will not back-track (That does the Atomic Bracket)
							"(?:\\\"|[^"])*"              # a double-quoted string
							|\'(?:\\\\\'|[^\'])*\'        # or a single quoted string
						)\s*                              #
					)*                                    # Tag arguments can be replaced many times.
				\s*
				\/?>                                      # Closing tag
			)
			|(?:                                          # Start match CDATA section
				<!\[CDATA\[.*?\]\]>
			)
		)/xs';

    /**
     * Pre-process the template source before it is
     * returned to the TemplateParser or passed to
     * the next TemplateProcessorInterface instance.
     *
     * @param string $templateSource
     * @return string
     */
    public function preProcessSource($templateSource)
    {
        $templateSource = $this->protectCDataSectionsFromParser($templateSource);
        $templateSource = $this->registerNamespacesFromTemplateSource($templateSource);
        $this->throwExceptionsForUnhandledNamespaces($templateSource);

        return $templateSource;
    }

    /**
     * Register all namespaces that are declared inside the template string
     *
     * @param string $templateSource
     * @return void
     */
    public function registerNamespacesFromTemplateSource($templateSource)
    {
        $viewHelperResolver = $this->renderingContext->getViewHelperResolver();
        if (preg_match_all(static::SPLIT_PATTERN_TEMPLATE_OPEN_NAMESPACETAG, $templateSource, $matchedVariables, PREG_SET_ORDER) > 0) {
            foreach ($matchedVariables as $namespaceMatch) {
                $viewHelperNamespace = $this->renderingContext->getTemplateParser()->unquoteString($namespaceMatch[2]);
                $phpNamespace = $viewHelperResolver->resolvePhpNamespaceFromFluidNamespace($viewHelperNamespace);
                if (stristr($phpNamespace, '/') === false) {
                    $viewHelperResolver->addNamespace($namespaceMatch[1], $phpNamespace);
                }
            }
        }

        $templateSource = preg_replace_callback(static::NAMESPACE_DECLARATION, function (array $matches) use ($viewHelperResolver) {
            $identifier = $matches['identifier'];
            $namespace = isset($matches['phpNamespace']) ? $matches['phpNamespace'] : null;
            if (strlen($namespace) === 0) {
                $namespace = null;
            }
            $viewHelperResolver->addNamespace($identifier, $namespace);
            return '';
        }, $templateSource);

        return $templateSource;
    }

    /**
     * Encodes areas enclosed in CDATA to prevent further parsing by the Fluid engine.
     * CDATA sections will appear as they are in the final rendered result.
     *
     * @param string $templateSource
     * @return mixed
     */
    public function protectCDataSectionsFromParser($templateSource)
    {
        $parts = preg_split('/(\<\!\[CDATA\[|\]\]\>)/', $templateSource, -1, PREG_SPLIT_DELIM_CAPTURE);
        $balance = 0;
        $content = '';
        foreach ($parts as $index => $part) {
            if ($part === '<![CDATA[') {
                $balance++;
            }

            if ($balance > 0) {
                $content .= $part;
                unset($parts[$index]);
            }

            if ($part === ']]>' && $balance > 0) {
                $balance--;
            }

            if ($balance === 0 && $content !== '') {
                $parts[$index] = '<f:format.base64Decode>' . base64_encode($content) . '</f:format.base64Decode>';
                $content = '';
            }
        }

        ksort($parts);
        return implode('', $parts);
    }

    /**
     * Throw an UnknownNamespaceException for any unknown and not ignored
     * namespace inside the template string.
     *
     * @param string $templateSource
     * @return void
     */
    public function throwExceptionsForUnhandledNamespaces($templateSource)
    {
        $viewHelperResolver = $this->renderingContext->getViewHelperResolver();
        $splitTemplate = preg_split(static::$EXTENDED_SPLIT_PATTERN_TEMPLATE_DYNAMICTAGS, $templateSource, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($splitTemplate as $templateElement) {

            if (preg_match(Patterns::$SCAN_PATTERN_TEMPLATE_VIEWHELPERTAG, $templateElement, $matchedVariables) > 0) {

                if (!$viewHelperResolver->isNamespaceValidOrIgnored($matchedVariables['NamespaceIdentifier'])) {
                    throw new UnknownNamespaceException('Unkown Namespace: ' . htmlspecialchars($matchedVariables[0]));
                }
                continue;
            } elseif (preg_match(Patterns::$SCAN_PATTERN_TEMPLATE_CLOSINGVIEWHELPERTAG, $templateElement, $matchedVariables) > 0) {
                continue;
            }

            $sections = preg_split(Patterns::$SPLIT_PATTERN_SHORTHANDSYNTAX, $templateElement, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            foreach ($sections as $section) {
                if (preg_match(Patterns::$SCAN_PATTERN_SHORTHANDSYNTAX_OBJECTACCESSORS, $section, $matchedVariables) > 0) {
                    preg_match_all(Patterns::$SPLIT_PATTERN_SHORTHANDSYNTAX_VIEWHELPER, $section, $shorthandViewHelpers, PREG_SET_ORDER);
                    if (is_array($shorthandViewHelpers) === true) {
                        foreach ($shorthandViewHelpers as $shorthandViewHelper) {
                            if (!$viewHelperResolver->isNamespaceValidOrIgnored($shorthandViewHelper['NamespaceIdentifier'])) {
                                throw new UnknownNamespaceException('Unkown Namespace: ' . $shorthandViewHelper['NamespaceIdentifier']);
                            }
                        }
                    }
                }
            }
        }
    }
}
