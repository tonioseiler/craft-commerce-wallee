<?php
namespace craft\commerce\wallee;

use craft\web\AssetBundle;


class CommerceWalleeBundle extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = '@craft/commerce/wallee/resources';

        /*$this->js = [
            'js/paymentForm.js',
        ];*/

        $this->css = [
            'css/commerce-wallee-cp.css',
        ];

        parent::init();
    }
}