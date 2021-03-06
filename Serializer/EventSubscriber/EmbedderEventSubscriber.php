<?php

namespace FSC\HateoasBundle\Serializer\EventSubscriber;

use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\TypeParser;
use JMS\Serializer\XmlSerializationVisitor;
use JMS\Serializer\EventDispatcher\Events;
use JMS\Serializer\EventDispatcher\Event;

use Metadata\MetadataFactoryInterface as JMSMetadataFactoryInterface;
use Symfony\Component\Form\FormView;
use Pagerfanta\PagerfantaInterface;

use FSC\HateoasBundle\Factory\ContentFactoryInterface;
use FSC\HateoasBundle\Factory\ParametersFactoryInterface;
use FSC\HateoasBundle\Metadata\RelationMetadataInterface;
use FSC\HateoasBundle\Metadata\RelationContentMetadataInterface;
use FSC\HateoasBundle\Metadata\RelationsManagerInterface;

class EmbedderEventSubscriber implements EventSubscriberInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        $methods = array();
        foreach (array('json', 'xml', 'yml') as $format) {
            $methods[] = array(
                'event' => Events::POST_SERIALIZE,
                'format' => $format,
                'method' => 'onPostSerialize'.('xml' == $format ? 'xml' : ''),
            );
        }

        return $methods;
    }

    protected $contentFactory;
    protected $serializerMetadataFactory;
    protected $relationsManager;
    protected $parametersFactory;
    protected $typeParser;
    protected $relationsJsonKey;

    public function __construct(
        ContentFactoryInterface $contentFactory,
        JMSMetadataFactoryInterface $serializerMetadataFactory,
        RelationsManagerInterface $relationsManager,
        ParametersFactoryInterface $parametersFactory,
        TypeParser $typeParser = null,
        $relationsJsonKey = null
    ) {
        $this->contentFactory = $contentFactory;
        $this->serializerMetadataFactory = $serializerMetadataFactory;
        $this->relationsManager = $relationsManager;
        $this->parametersFactory = $parametersFactory;
        $this->typeParser = $typeParser ?: new TypeParser();
        $this->relationsJsonKey = $relationsJsonKey ?: 'relations';
    }

    public function onPostSerializeXML(Event $event)
    {
        if (null === ($relationsContent = $this->contentFactory->create($event->getObject())) || empty($relationsContent)) {
            return;
        }

        $visitor = $event->getVisitor(); /** @var $visitor XmlSerializationVisitor */

        foreach ($relationsContent as $relationMetadata) {
            $relationContent = $relationsContent[$relationMetadata];

            $entryNode = $visitor->getDocument()->createElement($this->getRelationXmlElementName($relationMetadata, $relationContent));
            $visitor->getCurrentNode()->appendChild($entryNode);
            $visitor->setCurrentNode($entryNode);

            $visitor->getCurrentNode()->setAttribute('rel', $relationMetadata->getRel());

            $this->addRelationRelations($event, $relationContent, $relationMetadata);
            $node = $visitor->getNavigator()->accept(
                $relationContent,
                $this->getContentType($relationMetadata->getContent()),
                $visitor
            );
            if (null !== $node) {
                $visitor->getCurrentNode()->appendChild($node);
            }

            $visitor->revertCurrentNode();
        }
    }

    public function onPostSerialize(Event $event)
    {
        if (null === ($relationsData = $this->getOnPostSerializeData($event))) {
            return;
        }

        $event->getVisitor()->addData($this->relationsJsonKey, $relationsData);
    }

    public function getOnPostSerializeData(Event $event)
    {
        if (null === ($relationsContent = $this->contentFactory->create($event->getObject())) || empty($relationsContent)) {
            return;
        }

        $visitor = $event->getVisitor();

        $relationsData = array();
        foreach ($relationsContent as $relationMetadata) {
            $relationContent = $relationsContent[$relationMetadata];
            $this->addRelationRelations($event, $relationContent, $relationMetadata);
            $relationsData[$relationMetadata->getRel()] = $visitor->getNavigator()->accept(
                $relationContent,
                $this->getContentType($relationMetadata->getContent()),
                $visitor
            );
        }

        return $relationsData;
    }

    protected function getRelationXmlElementName(RelationMetadataInterface $relationMetadata, $content)
    {
        $elementName = null;

        if (null !== $relationMetadata->getContent()->getSerializerXmlElementName()) {
            $elementName = $relationMetadata->getContent()->getSerializerXmlElementName();
        } elseif (null !== $relationMetadata->getContent()->getSerializerXmlElementRootName()) {
            $classMetadata = $this->serializerMetadataFactory->getMetadataForClass(get_class($content));
            $elementName = $classMetadata->xmlRootName ?: $elementName;
        }

        if (null === $elementName && ('Pagerfanta\\PagerfantaInterface') && $content instanceof PagerfantaInterface) {
            $elementName = 'collection';
        } elseif (null === $elementName && ('Symfony\\Component\\Form\\FormView') && $content instanceof FormView) {
            $elementName = 'form';
        }

        return $elementName ?: 'relation';
    }

    protected function addRelationRelations(Event $event, $content, RelationMetadataInterface $relationMetadata)
    {
        $parameters = $this->parametersFactory->createParameters($event->getObject(), $relationMetadata->getParams());

        $this->relationsManager->addBasicRelations($content, $relationMetadata->getRoute(), $parameters);
    }

    protected function getContentType(RelationContentMetadataInterface $relationContentMetadata)
    {
        if (null === $relationContentMetadata->getSerializerType()) {
            return null;
        }

        return $this->typeParser->parse($relationContentMetadata->getSerializerType());
    }
}
