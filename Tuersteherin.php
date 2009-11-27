#!/usr/bin/env php
<?php
include("SmartIRC.php");

class Tuersteherin {

    private $SmartIRC;

    const Nickname = "Dagobert";
    const Realname = "Dagobert Duck";
    
    const Server   = "irc.euirc.net";
    const Port     = 6667;
    const Channels = '#whf';
    
    const IdleTimeout = 0;
   
    private $LoggedIn = array();
    private $idleTime = array();
    private $UserUIDs = array();

    function Tuersteherin() {
        $irc = $this->SmartIRC = &new Net_SmartIRC();
        $irc->setUseSockets(true);
        $irc->setChannelSyncing(true);
        $irc->setUserSyncing(true);
        $irc->setAutoReconnect(true);
        $irc->setDebug(SMARTIRC_DEBUG_ALL);

        setlocale (LC_ALL, 'de_DE');
        
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '.*', $this, 'updateIdle');
        $irc->registerActionhandler(SMARTIRC_TYPE_NICKCHANGE, '.*', $this, 'updateUUID');
        $irc->registerActionhandler(SMARTIRC_TYPE_QUIT, '.*', $this, 'removeUUID');


        $irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!login', $this, 'login');
        $irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!logout$', $this, 'logout');
        $irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!admins$', $this, 'admins');
        $irc->registerActionhandler(SMARTIRC_TYPE_QUIT, '.*', $this, 'logout');       
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!quit$', $this, 'quit');
        
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '/^.+\s'.self::Nickname.'$/i', $this, 'Selber');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!toblerone(\s|$)', $this, 'Toblerone');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!say(\s|$)', $this, 'Say');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!sayme(\s|$)', $this, 'SayMe');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!?ping(\?|!|\.)?$', $this, 'Ping');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^euda$', $this, 'Euda');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '/[-+]?[0-9]*[.,]?[0-9]+\s?chf/i', $this, 'CHFtoEUR');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!(time|date)(\s|$)', $this, 'Time');


        $irc->connect(self::Server, self::Port);
        $irc->login(self::Nickname, self::Realname);
        $irc->join(explode('|', self::Channels));

        if(self::IdleTimeout > 0) {
            $irc->registerTimehandler(5000, $this, 'checkIdle');
        }

        $irc->listen();
        $irc->disconnect();
    }

    function updateIdle(&$irc, &$ircdata) {
        $uuid = $ircdata->ident.'@'.$ircdata->host;

        if(!isset($this->UserUIDs[$uuid])) {
            $this->UserUIDs[$uuid] = $ircdata->nick;
        }

        if(!isset($this->idleTime[$ircdata->channel][$uuid])) {
            for($i=0;$i<4;$i++) {
                $this->idleTime[$ircdata->channel][$uuid][$i] = 0;
            }
        } else {
            for($i=3;$i>0;$i--) {
                $this->idleTime[$ircdata->channel][$uuid][$i] = 
                    $this->idleTime[$ircdata->channel][$uuid][$i-1];
            }
        }
        $timestamps = &$this->idleTime[$ircdata->channel][$uuid];
        $timestamps[0] = microtime(true);
        
        if(self::IdleTimeout != 0 && !$irc->isVoiced($ircdata->channel, $ircdata->nick)) {
            $irc->voice($ircdata->channel, $ircdata->nick);
        }
        
        if($timestamps[0] - $timestamps[3] < 2) {
            $irc->kick($ircdata->channel, $ircdata->nick, "Fluten des Kanals verboten!"); 
        }
    }

    function checkIdle(&$irc) {
        foreach(explode('|', self::Channels) as $channel) {
            $idleList = array();
            foreach($irc->getChannel($channel)->voices as $user=>$isVoiced) {
                $uuid = $this->getUUID($user);
                $timestamp = isset($this->idleTime[$channel][$uuid]) ?
                                $this->idleTime[$channel][$uuid][0] : 0;

                if(microtime(true) - $timestamp > self::IdleTimeout) {
                    $idleList[] = $user;
                }   
            }

            if(count($idleList) > 0) {
                $irc->mode($channel, '-'.str_repeat('v', count($idleList)).
                            ' '.implode(' ', $idleList));
            }
        }

/*
        foreach($this->idleTime as $channel=>$userdata) {
            foreach($userdata as $uuid=>$timestamps) {
                if(microtime(true) - $timestamps[0] > self::IdleTimeout) {
                    if($irc->isVoiced($channel, $this->getNickname($uuid))) {
                        $irc->devoice($channel, $this->getNickname($uuid));
                    }
                }
            }
        }
*/
    }

    function updateUUID(&$irc, &$ircdata) {
        $uuid = $ircdata->ident.'@'.$ircdata->host;
        $this->UserUIDs[$uuid] = $ircdata->message;
    }
    
    function removeUUID(&$irc, &$ircdata) {
        $uuid = $ircdata->ident.'@'.$ircdata->host;
        unset($this->UserUIDs[$uuid]);
        foreach($this->idleTime as $channel=>$user) {
            unset($this->idleTime[$channel][$uuid]);
        }
    }

    function getNickname($uuid) {
        return $this->UserUIDs[$uuid];
    }
    
    function getUUID($nickname) {
        return array_search($nickname, $this->UserUIDs);
    }

    function quit(&$irc, &$ircdata) {
        if($this->checkLogin($ircdata->host)) {
            $irc->quit("Heil Diskordia!");
        } elseif($irc->isOpped($ircdata->channel)) {
            $irc->kick($ircdata->channel, $ircdata->nick, "WUTSCHNAUBZETTER");
        } else {
            $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, "WUTSCHNAUBZETTER");
        }
    }
    
    function login(&$irc, &$ircdata) {
        if(!isset($ircdata->messageex[2])) return;
        $user = $ircdata->messageex[1];
        $pass = $ircdata->messageex[2];
        $Admins = parse_ini_file("Admins.txt");
        
        if(isset($Admins[$user]) && sha1($pass) == $Admins[$user]) {
            $this->LoggedIn[$ircdata->host] = true;
            $irc->message(SMARTIRC_TYPE_QUERY, $ircdata->nick, "Logged in");
        }
    }
    
    function checkLogin($host) {
        return isset($this->LoggedIn[$host]) ? $this->LoggedIn[$host] : false;
    }

    
    function admins(&$irc, &$ircdata) {
        if(!$this->checkLogin($ircdata->nick)) return;
        $irc->message(SMARTIRC_TYPE_QUERY, $ircdata->nick, "Logged in admins:");
        foreach($this->LoggedIn as $host=>$loggedin) {
            if($loggedin) {
                $irc->message(SMARTIRC_TYPE_QUERY, $ircdata->nick, $this->getNickname($host));
            }
        }
    }
    
    function logout(&$irc, &$ircdata) {
        if($this->checkLogin($ircdata->host)) {
            unset($this->LoggedIn[$ircdata->nick]);
            $irc->message(SMARTIRC_TYPE_QUERY, $ircdata->nick, "Logged out");
        }
    }

    function Selber(&$irc, &$ircdata) {

        preg_match("/^(?<selber>.+)\s".self::Nickname."$/i", $ircdata->message, $msg);
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, $msg['selber'].' '.$ircdata->nick);
    }
    
    function Toblerone(&$irc, &$ircdata) {
        $nick = $this->_message_line($ircdata->message, $ircdata->nick);
        $irc->message(SMARTIRC_TYPE_ACTION, $ircdata->channel, 'gibt '.$nick.' eine Toblerone!');
    }

    function Say(&$irc, &$ircdata) {
        $message = $this->_message_line($ircdata->message, "Ich sag nichts! :o");
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, $message);
    }

    function SayMe(&$irc, &$ircdata) {
        $message = $this->_message_line($ircdata->message, "Ich mach nichts! :o");
        $irc->message(SMARTIRC_TYPE_ACTION, $ircdata->channel, $message);
    }
    
    function Ping(&$irc, &$ircdata) {
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, 'Pong!');
    }

    function Time(&$irc, &$ircdata) {
        $irc->message(
            SMARTIRC_TYPE_CHANNEL, $ircdata->channel,
            "Aktuelle Zeit auf dem Server: ".date("l, d. F, H:i:s")." Uhr"
        );
    }

    function Euda(&$irc, &$ircdata) {
        if(rand(0, 1)) {
            $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, 'eudaR :o!');
        }
    }
    
    function CHFtoEUR(&$irc, &$ircdata) {
        preg_match("/(?<value>[-+]?[0-9]*[.,]?[0-9]+)\s?chf/i", $ircdata->message, $value);
        $chf = strtr($value['value'], ',', '.');
        $context = stream_context_create(array('http' => array('timeout' => 1))); 
        $eur = file_get_contents(
            "http://www.multimolti.com/apps/currencyapi/calculator.php".
            "?original=CHF&target=EUR&value=".$chf,
            0,
            $context
        );
        if($eur !== false) {
            $msg = $chf.' CHF sind exakt '.number_format($eur, 2, ',', '.').' EUR';
        } else {
            $eur = $chf * 0.66; /* leider existiert kein offizieller Durchschnitt */
            $msg = $chf.' CHF sind ungefaehr '.number_format($eur, 2, ',', '.').' EUR';
        }
        
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, $msg);
    }
    
    function _message_line($message, $fallback = '') {
        @list($cmd, $line) = explode(' ', $message, 2);
        $line = (is_null($line)) ? $fallback : $line;
        return $line;
    }

}

//preg_match("/(?<value>[0-9]*(\.|,)?[0-9]+)\s?chf/i", "52 chf", $value);
//var_dump($value);
$T = new Tuersteherin();

?>
