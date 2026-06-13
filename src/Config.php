<?php

namespace App;

class Config
{
    public const string DB_PATH = __DIR__ . '/../db/chat.db';
    public const string FILES_DB_PATH = __DIR__ . '/../db/files.db';
    public const string WORDS_PATH = __DIR__ . '/../words.txt';
    public const string BANNED_NAMES_PATH = __DIR__ . '/../banned_names.txt';
    public const string BAD_WORDS_PATH = __DIR__ . '/../bad_words.txt';
    public const string TITLE = 'Chat';
    public const string DATE_FORMAT = 'Y-m-d\TH:i:s\Z';
}
