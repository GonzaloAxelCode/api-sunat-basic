<?php



use Greenter\Model\DocumentInterface;
use Greenter\Report\Resolver\TemplateResolverInterface;

class MyTemplateResolver implements TemplateResolverInterface
{
    public function getTemplate(DocumentInterface $document): string
    {
        // Devuelve la ruta absoluta de tu plantilla
        return __DIR__ . '/templates/mi_boleta.html.twig';
    }
}
