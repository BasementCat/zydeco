<?php
	include 'src/ZydecoNode.php';
	include 'src/ZydecoParser.php';

	$parser=new ZydecoParser(file_get_contents('test.txt'));
	//echo '<pre>';
	//$parser->parse()->printTree();
	$parser->parse();
	echo $parser->getHtml();