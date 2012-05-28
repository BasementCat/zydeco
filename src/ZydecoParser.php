<?php
	class ZydecoParser{
		private $text;
		private $currentNode, $rootNode, $escape, $currentText, $beginningOfLine;
		private $lastToken, $lastListItemDepth;

		public $MediaWikiTables=false, $ImageBase='./', $HrefBase='./';

		public function reset(){
			$this->text='';
			$this->MediaWikiTables=true;
			$this->currentNode=new ZydecoNode();
			$this->rootNode=$this->currentNode;
			$this->currentText='';
			$this->escape=0;
			$this->beginningOfLine=1;
			$this->lastToken='';
			$this->lastListItemDepth=0;
			$this->ImageBase='./';
			$this->HrefBase='./';
		}

		public function setText($text){
			$this->text=$text;
		}

		public function __construct($text=''){
			$this->reset();
			$this->setText($text);
		}

		protected function getNextToken(){
			if(preg_match('%
				^(
					\w+			#Word chars (letters/numbers)
					|\|=		#Creole TH
					|\|}		#MW </table>
					|\|-		#MW TR
					|!			#MW TH
					|\|			#Creole TR/TD and MW TD
					|{\|		#MW <table>
					|\\+		#Line breaks (\\)
					|\*+		#ULs and <strong>
					|\#+		#OLs
					|={1,6}		#H1-H6
					|\[\[		#Links
					|\]\]		#end of links
					|://		#for finding embedded URLs
					|/+			#Italics
					|{{{		#nowiki (only for finding the start)
					|{{			#images
					|}}			#end of images
					|~			#escape char
					|[\r\n]+	#Any end-of-line (any length string consisting of only \r and \n)
					|[\t ]+		#Any whitespace (any length string consisting of only spaces and \t)
					|[^\s\w\\*#=\[\]/~]+	#Everything else
					)(.*)%sx', $this->text, $matches)){
				list($original, $token, $this->text)=$matches;
				return $token;
			}
			return null;
		}

		protected function endNode($tag=null, $cascade=false){
			if($tag!==null){
				if(!$cascade){
					if(!fnmatch($tag, $this->currentNode->Tag)) return false;
				}else{
					$this->addText();
					while($this->currentNode->Tag&&!fnmatch($tag, $this->currentNode->Tag))
						$this->currentNode=$this->currentNode->Parent;
				}
			}
			$this->addText();
			$this->currentNode=$this->currentNode->Parent?$this->currentNode->Parent:$this->rootNode;
			return true;
		}

		protected function addText(){
			if($this->currentText){
				$this->currentNode->addChild(new ZydecoNode('_', $this->currentText));
				$this->currentText='';
			}
		}

		protected function startNode($tag){
			$this->addText();
			$node=new ZydecoNode($tag, $this->currentNode);
			$this->currentNode=$node;
		}

		public function parse(){
			while(($token=$this->getNextToken())!==null){
				switch($token){
					case '~':
						if($this->escape){ $this->currentText.=$token; break; }
						$this->escape=2;
						break;
					case '=': case '==': case '===': case '====': case '=====': case '======':
						if($this->escape){ $this->currentText.=$token; break; }
						$tag=sprintf('h%d', strlen($token));
						if(!$this->endNode($tag, $this->currentNode->childOf($tag))){
							if($this->beginningOfLine)
								$this->startNode($tag);
							else
								$this->currentText.=$token;
						}
						break;
					case '**':
						if($this->escape){ $this->currentText.=$token; break; }
						//this one's a pain - can either be <strong> or 2nd level <ul> or <li> within 2nd level UL
						if($this->beginningOfLine){
							if($this->currentNode->Tag=='li'){
								//second level ul
								$this->startNode('ul');
								$this->startNode('li');
								break;
							}elseif($this->currentNode->Tag=='ul'){
								$this->startNode('li');
								break;
							}
						}
						//if we haven't broken out of the switch yet, then it's probably bold (or the writer doesn't know what they're doing)
						if(!$this->endNode('strong')) $this->startNode('strong');
						break;
					case '//':
						if($this->escape){ $this->currentText.=$token; break; }
						if(!$this->endNode('em')) $this->startNode('em');
						break;
					case '[[':
						if($this->escape||$this->currentNode->descendentOf('a')){ $this->currentText.=$token; break; }
						$this->startNode('a');
						break;
					case ']]':
						if($this->escape||!$this->currentNode->descendentOf('a')){ $this->currentText.=$token; break; }
						if(!isset($this->currentNode->Attributes['href']))
							$this->currentNode->setAttribute('href', $this->HrefBase.$this->currentText);
						$this->endNode('a', true);
						break;
					case '{{':
						if($this->escape){ $this->currentText.=$token; break; }
						$this->startNode('img');
						break;
					case '}}':
						if($this->escape){ $this->currentText.=$token; break; }
						if(!isset($this->currentNode->Attributes['src']))
							$this->currentNode->setAttribute('src', $this->ImageBase.$this->currentText);
						else
							$this->currentNode->setAttribute('alt', $this->currentText);
						$this->currentText='';
						$this->endNode('img', true);
						break;
					case '{{{':
						if($this->escape){ $this->currentText.=$token; break; }
						if(preg_match("#[\r\n]$#", $this->currentText)&&preg_match("#^[ \t]*[\r\n]#", $this->currentText)){
							//start of nowiki on a single line, no preceding whitespace
							$this->addText();
							if(preg_match("#^(.*?)(?<=[\r\n])}}}(.*)#s", $this->text, $matches)){
								$this->startNode('pre');
								$this->currentText=$matches[1];
								$this->endNode('pre');
								$this->text=$matches[2];
								break;
							}
						}else{
							//inline nowiki
							$this->addText();
							if(preg_match("#^(.*?)}}}(.*)#s", $this->text, $matches)){
								$this->startNode('span');
								$this->currentNode->setAttribute('class', 'nowiki');
								$this->currentText=$matches[1];
								$this->endNode('span');
								$this->text=$matches[2];
								break;
							}
						}
					case '://':
						if($this->escape||$this->currentNode->descendentOf('a')){ $this->currentText.=$token; break; }
						//startNode('a');
						$url=$this->lastToken.$token;
						if(preg_match('#^([^\s]+)(.*)#s', $this->text, $matches)){
							$this->currentText=preg_replace("#".str_replace("#", "\\#", $this->lastToken)."$#", '', $this->currentText);
							$original=array_shift($matches);
							$url.=array_shift($matches);
							$this->text=array_shift($matches);
							if(preg_match('#[,.?!:;"\']$#', $url, $matches)){
								$url=preg_replace('#[,.?!:;"\']$#', '', $url);
								$this->text=$matches[0].$this->text;
							}
							//$text=preg_replace("#^".str_replace("#", "\\#", $url)."#", '', $text);
							$this->startNode('a');
							$this->currentNode->setAttribute('href', $url);
							$this->currentText=$url;
							$this->endNode('a');
						}else{
							$this->currentText.=$url;
						}
						break;
					case '\\\\':
						if($this->escape){ $this->currentText.=$token; break; }
						$this->startNode('br');
						$this->endNode('br');
						break;
					case '----':
						if($this->escape||!preg_match("#[\r\n][ \t]*$#", $this->currentText)||!preg_match("#^[ \t]*[\r\n]#", $this->text)){ $this->currentText.=$token; break; }
						$this->startNode('hr');
						$this->endNode('hr');
						break;
					case '|':
						if($this->escape){ $this->currentText.=$token; break; }
						if($this->currentNode->Tag=='a'||$this->currentNode->Tag=='img'){
							$is_a=($this->currentNode->Tag=='a');
							$this->currentNode->setAttribute($is_a?'href':'src', ($is_a?$this->HrefBase:$this->ImageBase).$this->currentText);
							$this->currentText='';
							break;
						}
					case '|=':
						if($this->escape){ $this->currentText.=$token; break; }
						$tag=($token=='|'?'td':'th');
						switch($this->currentNode->Tag){
							case 'table':
								//we should be at the beginning of a line here, if we're not something's broken
								$this->currentText=''; //ignore text in between table parts
								$this->startNode('tr');
								$this->startNode($tag);
								break;
							case 'th':
							case 'td':
								$this->endNode();
							case 'tr':
								if(preg_match("#^[ \t]*[\r\n]#", $this->text)){
									$this->endNode('tr');
								}else{
									$this->startNode($tag);
								}
								break;
							default:
								if($this->beginningOfLine){
									$this->startNode('table');
									$this->startNode('tr');
									$this->startNode($tag);
								}else{
									$this->currentText.=$token;
									break;
								}
								break;
						}
						break;
					case '{|':
						if($this->escape||!$this->MediaWikiTables||!$this->beginningOfLine){ $this->currentText.=$token; break; }
						$this->startNode('table');
						$this->startNode('tr');
						break;
					case '!':
						if($this->escape||!$this->MediaWikiTables||!$this->beginningOfLine){ $this->currentText.=$token; break; }
						$this->endNode('th');
						$this->endNode('td');
						$this->startNode('th');
						break;
					case '|-':
						if($this->escape||!$this->MediaWikiTables||!$this->beginningOfLine){ $this->currentText.=$token; break; }
						$this->endNode('th');
						$this->endNode('td');
						$this->endNode('tr');
						$this->startNode('tr');
						break;
					case '|}':
						if($this->escape||!$this->MediaWikiTables||!$this->beginningOfLine){ $this->currentText.=$token; break; }
						$this->endNode('th');
						$this->endNode('td');
						$this->endNode('tr');
						$this->endNode('table');
						break;
					default:
						if(preg_match("%\*+|#+%", $token)&&$this->beginningOfLine){
							$listItemDepth=strlen($token);
							$listType=($token[0]=='*'?'ul':'ol');
							if($listItemDepth>$this->lastListItemDepth){
								for($this->lastListItemDepth; $this->lastListItemDepth<$listItemDepth; $this->lastListItemDepth++){
									$this->startNode($listType);
									$this->startNode('li');
								}
							}elseif($listItemDepth==$this->lastListItemDepth){
								$this->endNode('li');
								$this->startNode('li');
							}elseif($listItemDepth<$this->lastListItemDepth){
								for($this->lastListItemDepth; $this->lastListItemDepth>0; $this->lastListItemDepth--){
									$this->endNode('li');
									$this->endNode('?l');
								}
							}
							break;
						}elseif(preg_match("#[\r\n]+#", $token)){
							$this->beginningOfLine=2;
							if($this->currentNode->descendentOf('h*')) $this->endNode('h*', true);
							/*if($currentNode->descendentOf('tr')){
								endNode('th');
								endNode('td');
								endNode('tr');
							}*/
						}
						if(!$this->currentNode->Tag){ $this->startNode('p'); if(preg_match("#[\r\n]+#", $token)) break; }
						if(preg_match("#\r\n\r\n|\r\r|\n\n#", $token)){
							if($this->lastListItemDepth){
								for($this->lastListItemDepth; $this->lastListItemDepth>0; $this->lastListItemDepth--){
									$this->endNode('li');
									$this->endNode('?l');
								}
							}
							if($this->endNode('p', $this->currentNode->childOf('p'))) break;
						}
						if($this->escape) $currentText.='~';
						$this->currentText.=$token;
						break;
				}
				if($this->escape) $this->escape--;
				if($this->beginningOfLine) $this->beginningOfLine--;
				$this->lastToken=$token;
			}
			$this->addText();
			return $this->rootNode;
		}

		public function getHtml($whitespace=false){
			return $this->rootNode->render($whitespace);
		}
	}