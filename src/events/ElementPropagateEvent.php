<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\events;

/**
 * ElementPropagateEvent class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class ElementPropagateEvent extends CancelableEvent
{
    // Properties
    // =========================================================================

    /**
     * @var ElementInterface|null The element model associated with the event.
     */
    public $element;
}