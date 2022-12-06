<?php

use Zotlabs\Extend\Route;

/**
 * Name: Flashcards
 * Description: Grandma style learning method that uses spaced repetition as a learning technique.
 * Version: 2.08
 * Author: Tom Wiedenhöft (ojrandom@protonmail.com)
 * Maintainer: Tom Wiedenhöft (ojrandom@protonmail.com)
 *
 */
function flashcards_load() {
    Route::register('addon/flashcards/Mod_Flashcards.php', 'flashcards');
}

function flashcards_unload() {
    Route::unregister('addon/flashcards/Mod_Flashcards.php', 'flashcards');
}
