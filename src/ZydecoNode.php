<?php
	class ZydecoNode{
		public $Tag, $Parent, $Children, $Text, $Attributes;

		//public function __construct($tag, $parent=null, $children=array(), $text=null){
		public function __construct(){
			$args=func_get_args();
			list($tag, $arg1, $arg2, $arg3)=array_pad($args, 4, null);
			if($tag=='_'){
				$text=$arg1;
				$parent=null;
				$children=array();
			}else{
				list($parent, $children, $text)=array($arg1, $arg2, $arg3);
				if($parent) $parent->addChild($this);
			}
			$this->Tag=strtolower($tag);
			$this->Parent=$parent;
			$this->Children=$children;
			$this->Text=$text;
		}

		public function addChild($child){
			$child->Parent=$this;
			$this->Children[]=$child;
		}

		public function setAttribute($attr, $value){
			$this->Attributes[$attr]=$value;
		}

		public function childOf($tag){
			$tempNode=$this->Parent;
			while($tempNode->Tag&&!fnmatch($tag, $tempNode->Tag))
				$tempNode=$tempNode->Parent;
			return fnmatch($tag, $tempNode->Tag);
		}

		public function descendentOf($tag){
			//this INCLUDES the current tag!
			/*$tempNode=$this;
			while($tempNode->Tag&&!fnmatch($tag, $tempNode->Tag))
				$tempNode=$tempNode->Parent;
			return fnmatch($tag, $tempNode->Tag);*/
			return $this->getNearest($tag)?true:false;
		}

		public function getNearest($tag){
			$tempNode=$this;
			while($tempNode->Tag&&!fnmatch($tag, $tempNode->Tag))
				$tempNode=$tempNode->Parent;
			if(fnmatch($tag, $tempNode->Tag)) return $tempNode;
			return false;
		}

		public function getNext($tag){
			if(fnmatch($tag, $this->Tag)) return $this;
			if(is_array($this->Children)){
				foreach($this->Children as $child)
					if(fnmatch($tag, $child->Tag)) return $child;
				foreach($this->Children as $child){
					$test=$child->getNext($tag);
					if($test) return $test;
				}
			}
			return false;
		}

		public function getAll($tag){
			$all=array();
			if(fnmatch($tag, $this->Tag)) $all[]=$this;
			if(is_array($this->Children)){
				/*foreach($this->Children as $child)
					if(fnmatch($tag, $child->Tag)) $all[]=$child;*/
				foreach($this->Children as $child)
					$all=array_merge($all, $child->getAll($tag));
			}
			return $all;
		}

		public function asText(){
			$r=array();
			foreach($this->getAll('_') as $txt) $r[]=$txt->Text;
			return implode("", $r);
		}

		public function render($renderWhitespace=false, $level=0){
			$r='';
			$isSelfClosing=in_array($this->Tag, array('img', 'br', 'hr', '_'));
			if($this->Tag){
				if($renderWhitespace) $r.=str_repeat("\t", $level);
				// '' is the root tag
				if($this->Tag=='_')
					$r.=$this->Text;
				else{
					if(is_array($this->Attributes)){
						$attrs=array();
						foreach($this->Attributes as $a=>$v) $attrs[]="$a=\"$v\"";
						$attrs=implode(' ', $attrs);
					}else $attrs=null;
					$r.=sprintf('<%s %s %s>', $this->Tag, $attrs, $isSelfClosing?' /':'');
				}
			}
			if(is_array($this->Children)){
				foreach($this->Children as $child)
					$r.=$child->render($renderWhitespace, $level+1);
			}
			if($this->Tag&&!$isSelfClosing) $r.=sprintf('</%s>', $this->Tag);
			if($renderWhitespace&&$this->Tag) echo "\n";
			return $r;
		}

		public function printTree($level=0){
			if($this->Tag){
				echo str_repeat('-', $level), '&gt; ', $this->Tag;
				if($this->Tag=='_') echo str_replace(array("\r", "\n", "\t", " "), array("&larr;", "&darr;", "&rarr;", "&middot;"), $this->Text);
				echo "\n";
			}
			if(is_array($this->Children)){
				foreach($this->Children as $child)
					$child->printTree($level+1);
			}
		}
	}