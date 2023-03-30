<?php

namespace Hummingbird\Mod1\Humage;

use \Magento\Catalog\Api\Data\CategoryInterface;

class Test
{
   protected $category;
   protected $dataArr;
   protected $stringPassed;

   public function __construct(
       CategoryInterface $category,
       $stringPassed = "StringPassed",
       $dataArr = ["injected","in","constructor"]
   )
   {  
       $this->category = $category;
       $this->stringPassed = $stringPassed;
       $this->dataArr = $dataArr;
   }


   public function displayParams()
   {
       $resultString = "";
       foreach($this->dataArr as $data){
           $resultString = $resultString . $data;
       }
       $finalString = $this->stringPassed . ' ' . $resultString;    
       return $finalString;
   }
}
