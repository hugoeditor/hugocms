<?php
function serachInItem($text, $term)
{
	if(NULL !== $text && is_string($text))
	{
		return stripos($text, $term) !== false;
	}
	elseif(NULL !== $text && is_array($text))
	{
		foreach($text as $value)
		{
			return stripos($value, $term) !== false;
		}
	}
	return false;
}

echo '<div class="album py-5 bg-light">';
echo '<div class="container">';
echo '<h1>Suchergebnisse</h1><p>';

if(isset($_GET['term']) && !empty($_GET['term']))
{
	$term = $_GET['term'];
	$index_file = file_get_contents("../index.json");
	$index = json_decode($index_file);
	
	foreach($index as $key => $item)
	{
		$found = false;
		$found = $found || serachInItem($item->title, $term);
		$found = $found || serachInItem($item->category, $term);
		$found = $found || serachInItem($item->content, $term);
		$found = $found || serachInItem($item->keywords, $term);
		$found = $found || serachInItem($item->permalink, $term);

		if(!$found)
		{
			unset($index[$key]);
		}
	}

	if(0 == count($index))
	{
		echo '<h2>Die Suche ergab keine Treffer.</h2>';
	}
	else
	{
		foreach($index as $result)
		{
			echo '<p class="pt-2">';
			echo '<a href="'.$result->permalink.'"><h2>'.$result->title.'</h2></a>';
			echo '<p>'.$result->content.'</p>';
			echo '</p>';
		}
	}
}
else
{
	echo '<h2>Die Suche ergab keine Treffer.</h2>';
}

echo '</p></div></div>';

