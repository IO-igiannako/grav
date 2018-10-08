<?php

/**
 * @package    Grav\Framework\Flex
 *
 * @copyright  Copyright (C) 2015 - 2018 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Framework\Flex;

use Doctrine\Common\Collections\Criteria;
use Grav\Common\Debugger;
use Grav\Common\Grav;
use Grav\Common\Twig\Twig;
use Grav\Framework\ContentBlock\HtmlBlock;
use Grav\Framework\Object\ObjectCollection;
use Grav\Framework\Flex\Interfaces\FlexCollectionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class FlexCollection
 * @package Grav\Framework\Flex
 */
class FlexCollection extends ObjectCollection implements FlexCollectionInterface
{
    /** @var FlexDirectory */
    private $_flexDirectory;

    /**
     * @return array
     */
    public static function getCachedMethods()
    {
        return [
            'getTypePrefix' => true,
            'getType' => true,
            'getFlexDirectory' => true,
            'getCacheKey' => true,
            'getCacheChecksum' => true,
            'getTimestamp' => true,
            'hasProperty' => true,
            'getProperty' => true,
            'hasNestedProperty' => true,
            'getNestedProperty' => true,
            'orderBy' => true,

            'authorize' => true
        ];
    }

    /**
     * @param array $elements
     * @param FlexDirectory|null $flexDirectory
     * @throws \InvalidArgumentException
     */
    public function __construct(array $elements = [], FlexDirectory $flexDirectory = null)
    {
        parent::__construct($elements);

        if ($flexDirectory) {
            $this->setFlexDirectory($flexDirectory)->setKey($flexDirectory->getType());
        }
    }

    /**
     * Creates a new instance from the specified elements.
     *
     * This method is provided for derived classes to specify how a new
     * instance should be created when constructor semantics have changed.
     *
     * @param array $elements Elements.
     *
     * @return static
     * @throws \InvalidArgumentException
     */
    protected function createFrom(array $elements)
    {
        return new static($elements, $this->_flexDirectory);
    }

    /**
     * @return string
     */
    protected function getTypePrefix()
    {
        return 'c.';
    }

    /**
     * @param bool $prefix
     * @return string
     */
    public function getType($prefix = true)
    {
        $type = $prefix ? $this->getTypePrefix() : '';

        return $type . $this->_flexDirectory->getType();
    }

    /**
     * @param string $layout
     * @param array $context
     * @return HtmlBlock
     * @throws \Exception
     * @throws \Throwable
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Syntax
     */
    public function render($layout = null, array $context = [])
    {
        if (null === $layout) {
            $layout = 'default';
        }

        $grav = Grav::instance();

        /** @var Debugger $debugger */
        $debugger = $grav['debugger'];
        $debugger->startTimer('flex-collection-' . ($debugKey =  uniqid($this->getType(false), false)), 'Render Collection ' . $this->getType(false));

        $cache = $key = null;
        foreach ($context as $value) {
            if (!\is_scalar($value)) {
                $key = false;
            }
        }

        if ($key !== false) {
            $key = md5($this->getCacheKey() . '.' . $layout . json_encode($context));
            $cache = $this->_flexDirectory->getCache('render');
        }

        try {
            $data = $cache ? $cache->get($key) : null;

            $block = $data ? HtmlBlock::fromArray($data) : null;
        } catch (InvalidArgumentException $e) {
            $debugger->addException($e);
            $block = null;
        } catch (\InvalidArgumentException $e) {
            $debugger->addException($e);
            $block = null;
        }

        $checksum = $this->getCacheChecksum();
        if ($block && $checksum !== $block->getChecksum()) {
            $block = null;
        }

        if (!$block) {
            $block = HtmlBlock::create($key);
            $block->setChecksum($checksum);

            $grav->fireEvent('onFlexCollectionRender', new Event([
                'collection' => $this,
                'layout' => &$layout,
                'context' => &$context
            ]));

            $output = $this->getTemplate($layout)->render(
                ['grav' => $grav, 'block' => $block, 'collection' => $this, 'layout' => $layout] + $context
            );

            $block->setContent($output);

            try {
                $cache && $cache->set($key, $block->toArray());
            } catch (InvalidArgumentException $e) {
                $debugger->addException($e);
            }
        }

        $debugger->stopTimer('flex-collection-' . $debugKey);

        return $block;
    }

    /**
     * @param FlexDirectory $type
     * @return $this
     */
    public function setFlexDirectory(FlexDirectory $type)
    {
        $this->_flexDirectory = $type;

        return $this;
    }

    /**
     * @return FlexDirectory
     */
    public function getFlexDirectory() //: FlexDirectory
    {
        return $this->_flexDirectory;
    }

    /**
     * @return string
     */
    public function getCacheKey()
    {
        return $this->getType(true) . '.' . sha1(json_encode($this->call('getKey')));
    }

    /**
     * @return string
     */
    public function getCacheChecksum()
    {
        return sha1(json_encode($this->getTimestamps()));
    }

    /**
     * @return int[]
     */
    public function getTimestamps()
    {
        return $this->call('getTimestamp');
    }

    /**
     * @param string $action
     * @param string|null $scope
     * @return FlexCollection
     */
    public function authorize(string $action, string $scope = null)
    {
        $list = $this->call('authorize', [$action, $scope]);
        $list = \array_filter($list);

        return $this->select(array_keys($list));
    }

    /**
     * @param string $value
     * @param string $field
     * @return object|null
     */
    public function find($value, $field = 'id')
    {
        if ($value) foreach ($this as $element) {
            if (strtolower($element->getProperty($field)) === strtolower($value)) {
                return $element;
            }
        }

        return null;
    }

    /**
     * @param array $ordering
     * @return FlexCollection
     */
    public function orderBy(array $ordering)
    {
        $criteria = Criteria::create()->orderBy($ordering);

        return $this->matching($criteria);
    }

    /**
     * @param int $start
     * @param int|null $limit
     * @return FlexCollection
     */
    public function limit($start, $limit = null)
    {
        return $this->createFrom($this->slice($start, $limit));
    }

    /**
     * Select items from collection.
     *
     * Collection is returned in the order of $keys given to the function.
     *
     * @param array $keys
     * @return FlexCollection
     */
    public function select(array $keys)
    {
        $list = [];
        foreach ($keys as $key) {
            if ($this->containsKey($key)) {
                $list[$key] = $this->get($key);
            }
        }

        return $this->createFrom($list);
    }

    /**
     * Un-select items from collection.
     *
     * Collection is returned in the order of $keys given to the function.
     *
     * @param array $keys
     * @return FlexCollection
     */
    public function unselect(array $keys)
    {
        return $this->select(array_diff($this->getKeys(), $keys));
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        $elements = [];

        /**
         * @var string $key
         * @var FlexObject $object
         */
        foreach ($this->getElements() as $key => $object) {
            $elements[$key] = $object->jsonSerialize();
        }

        return $elements;
    }

    /**
     * @param string $layout
     * @return \Twig_Template
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Syntax
     */
    protected function getTemplate($layout) //: \Twig_Template
    {
        $grav = Grav::instance();

        /** @var Twig $twig */
        $twig = $grav['twig'];

        try {
            return $twig->twig()->resolveTemplate(["flex-objects/layouts/{$this->getType(false)}/collection/{$layout}.html.twig"]);
        } catch (\Twig_Error_Loader $e) {
            /** @var Debugger $debugger */
            $debugger = Grav::instance()['debugger'];
            $debugger->addException($e);

            return $twig->twig()->resolveTemplate(["flex-objects/layouts/404.html.twig"]);
        }
    }

    /**
     * @param $type
     * @return FlexDirectory
     */
    protected function getRelatedDirectory($type) : ?FlexDirectory
    {
        /** @var Flex $flex */
        $flex = Grav::instance()['flex_objects'];

        return $flex->getDirectory($type);
    }
}