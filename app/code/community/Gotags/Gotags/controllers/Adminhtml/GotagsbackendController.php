<?php
class Gotags_Gotags_Adminhtml_GotagsbackendController extends Mage_Adminhtml_Controller_Action
{
	public function indexAction()
    {
       $this->loadLayout();
	   $this->_title($this->__("GOtags"));
	   $this->renderLayout();
    }
}