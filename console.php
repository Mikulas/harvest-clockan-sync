<?php

function clearScreen()
{
    array_map(create_function('$a', 'print chr($a);'), array(27, 91, 72, 27, 91, 50, 74));
}

function clearLine($length = 140) {
	echo "\r" . str_repeat(" ", $length) . "\r";
}

class Colorize
{

	/*
		https://gist.github.com/498693
		30 'black'
		31 'red'
		32 'green'
		33 'yellow'
		34 'blue'
		35 'magenta'
		36 'cyan'
		37 'white'
	*/

	public static function blue($text)
	{
		return self::color($text, "34");
	}

	public static function green($text)
	{
		return self::color($text, "32");
	}

	public static function red($text)
	{
		return self::color($text, "31");
	}

	public static function cyan($text)
	{
		return self::color($text, "36");
	}

	public static function magenta($text)
	{
		return self::color($text, "35");
	}

	public static function white($text)
	{
		return self::color($text, "37");
	}

	public static function bg_blue($text)
	{
		return self::color($text, "44");
	}

	public static function bg_magenta($text)
	{
		return self::color($text, "45");
	}

	private static function color($text, $options)
	{
		return "\033[{$options}m{$text}\033[0m";
	}
}
