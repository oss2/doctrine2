<?php

namespace Oss2\Doctrine2;

/**
 * Oss2 Doctrine2 WithPreferences
 *
 * Functions to add preference functionality to entities such as
 * users / customers / companies / etc
 *
 * Copyright (c) 2014, Open Source Solutions Limited, Dublin, Ireland
 * All rights reserved.
 *
 * Contact: Barry O'Donovan - info (at) opensolutions (dot) ie
 *          http://www.opensolutions.ie/
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.
 *
 * It is also available through the world-wide-web at this URL:
 *     http://www.opensolutions.ie/licenses/mit
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@opensolutions.ie so we can send you a copy immediately.
 *
 * @category   Oss2
 * @package    Oss2_Doctrine2
 * @copyright  Copyright (c) 2014, Open Source Solutions Limited
 * @license    http://www.opensolutions.ie/licenses/mit MIT License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 */

use \Doctrine\ORM\Mapping as ORM;
use \Oss2\Doctrine2\WithPreferences\IndexLimitException as IndexLimitException;

trait WithPreferences
{

    /**
     * The full name of the class
     *
     * @var string
     * @access protected
     */
    protected $_fullClassName = null;


    /**
     * The short name of the class
     *
     * @var string
     * @access protected
     */
    protected $_shortClassName = null;


    /**
     * The name of the preference class for this class
     * @var string
     */
    protected $_preferenceClassName = null;


    /**
     * Returns with all the preferences as a Doctrine Collection.
     *
     * @param void
     * @return object
     */
    public function getPreferences()
    {
        return $this->{ 'get' . $this->_getPreferenceClassName() . 's' }();
    }


    /**
     * Return the entity object of the named preference
     *
     * @param string $name The named attribute / preference to check for
     * @param int $index default null If an indexed preference, get a specific index
     * @param boolean $includeExpired default false If true, include preferences even if they have expired.
     * @return WithPreference If the named preference is not defined, returns FALSE; otherwise it returns the Doctrine_Record
     */
    public function loadPreference( $name, $index = null, $includeExpired = false )
    {
        foreach( $this->getPreferences() as $pref )
        {
            if( ( $pref->getName() == $name ) && ( $pref->getIx() === $index ) )
            {
                if( $includeExpired || !$pref->getExpiresAt() || ( $pref->getExpiresAt() > new DateTime() ) )
                    return $pref;

                return false;
            }
        }

        return false;
    }


    /**
     * Does the named preference exist or not?
     *
     * WARNING: Evaluate the return of this function using !== or === as a preference such as '0'
     * will evaluate as false otherwise.
     *
     * @param string $name The named attribute / preference to check for
     * @param int $index default null If an indexed preference, get a specific index
     * @param boolean $includeExpired default false If true, include preferences even if they have expired.
     * @return boolean|string If the named preference is not defined or has expired, returns FALSE; otherwise it returns the preference
     * @see getPreference()
     */
    public function hasPreference( $name, $index = null, $includeExpired = false )
    {
        return $this->getPreference( $name, $index, $includeExpired );
    }


    /**
     * Get the named preference's VALUE
     *
     * WARNING: Evaluate the return of this function using !== or === as a preference such as '0'
     * will evaluate as false otherwise.
     *
     * @param string $name The named attribute / preference to check for
     * @param int $index default null If an indexed preference, get a specific index
     * @param boolean $includeExpired default false If true, include preferences even if they have expired.
     * @return boolean|string If the named preference is not defined or has expired, returns FALSE; otherwise it returns the preference
     * @see loadPreference()
     */
    public function getPreference( $name, $index = null, $includeExpired = false )
    {
        $pref = $this->loadPreference( $name, $index, $includeExpired );

        if( !$pref )
            return false;

        return $pref->getValue();
    }


    /**
     * Set or update a preference
     *
     * @param string $name The preference name
     * @param string $value The value to assign to the preference
     * @param int|string|object $expires default null The expiry as a UNIX timestamp, datetime string or DateTime object. null means never.
     * @param int $index default null If an indexed preference, set a specific index number.
     * @return \Oss2\Doctrine2\WithPreferences An instance of this object for fluid interfaces.
     */
    public function setPreference( $name, $value, $expires = null, $index = null )
    {
        $pref = $this->loadPreference( $name, $index );

        if( is_int( $expires ) )
            $expires = new DateTime( date( 'Y-m-d H:i:s', $expires ) );
        elseif( is_string( $expires ) )
            $expires = new DateTime( $expires );

        if( $pref )
        {
            $pref->setValue( $value );
            $pref->setExpiresAt( $expires );
            $pref->setIx( $index );

            return $this;
        }

        $pref = $this->_createPreferenceEntity( $this );
        $pref->setName( $name );
        $pref->setValue( $value );
        $pref->setCreatedAt( new DateTime() );
        $pref->setExpiresAt( $expires );
        $pref->setIx( $index );

        D2EM::persist( $pref );

        return $this;
    }



    /**
     * Add an indexed preference
     *
     * Let's say we need to add a list of email addresses as a preference where the following is
     * the list:
     *
     *     $emails = [ 'a@b.c', 'd@e.f', 'g@h.i' ];
     *
     * then we could add these as an indexed preference as follows for a given User $u:
     *
     *     $u->addPreference( 'mailing_list.goalies.email', $emails );
     *
     * which would result in database entries as follows:
     *
     *     attribute                      index   op   value
     *     ------------------------------------------------------
     *     | mailing_list.goalies.email | 0     | =  | a@b.c    |
     *     | mailing_list.goalies.email | 1     | =  | d@e.f    |
     *     | mailing_list.goalies.email | 2     | =  | g@h.i    |
     *     ------------------------------------------------------
     *
     * we could then add a fourth address as follows:
     *
     *     $u->addPreference( 'mailing_list.goalies.email', 'j@k.l' );
     *
     * which would result in database entries as follows:
     *
     *     attribute                      index   op   value
     *     ------------------------------------------------------
     *     | mailing_list.goalies.email | 0     | =  | a@b.c    |
     *     | mailing_list.goalies.email | 1     | =  | d@e.f    |
     *     | mailing_list.goalies.email | 2     | =  | g@h.i    |
     *     | mailing_list.goalies.email | 3     | =  | j@k.l    |
     *     ------------------------------------------------------
     *
     *
     * ===== BEGIN NOT IMPLEMENTED =====
     *
     * If out list was to be of names and emails, then we could create an array as follows:
     *
     *     $emails = [
     *         [ 'name' => 'John Smith', 'email' => 'a@b.c' ],
     *         [ 'name' => 'David Blue', 'email' => 'd@e.f' ]
     *     ];
     *
     * then we could add these as an indexed preference as follows for a given User $u:
     *
     *     $u->addPreference( 'mailing_list.goalies', $emails );
     *
     * which would result in database entries as follows:
     *
     *     attribute                      index   op   value
     *     --------------------------------------------------------
     *     | mailing_list.goalies!email | 0     | =  | a@b.c      |
     *     | mailing_list.goalies!name  | 0     | =  | John Smith |
     *     | mailing_list.goalies!email | 1     | =  | d@e.f      |
     *     | mailing_list.goalies!name  | 1     | =  | David Blue |
     *     --------------------------------------------------------
     *
     * We can further be specific on operator for each one as follows:
     *
     *     $emails = [
     *                  [ 'name' => [ value = 'John Smith', expires = '123456789' ] ]
     *               ];
     *
     * Note that in the above form, value is required but if expires is not set, it will be taken from the function parameters.
     *
     * ===== END NOT IMPLEMENTED =====
     *
     * @param string $name The preference name
     * @param string $value The value to assign to the preference
     * @param int $expires default null The expiry as a UNIX timestamp. null which means never.
     * @param int $max The maximum index allowed. Defaults to 0 meaning no limit.
     * @return \Oss2\Doctrine2\WithPreferences An instance of this object for fluid interfaces.
     * @throws \Oss2\Doctrine2\WithPreferences\IndexLimitException If $max is set and limit exceeded
     */
    public function addIndexedPreference( $name, $value, $expires = null, $max = 0 )
    {
        // what's the current highest index and how many is there?
        $highest = -1;
        $count = 0;

        foreach( $this->getPreferences() as $pref )
        {
            if( $pref->getName() == $name )
            {
                $count++;

                if( $pref->getIx() > $highest )
                    $highest = $pref->getIx();
            }
        }

        if( $max && ( $count >= $max ) )
            throw new IndexLimitException( 'Requested maximum number of indexed preferences reached' );

        if( is_array( $value ) )
        {
            foreach( $value as $v )
                $this->setPreference( $name, $value, $expires, ++$highest );
        }
        else
        {
            $this->setPreference( $name, $value, $expires, ++$highest );
        }

        return $this;
    }


    /**
     * Clean expired preferences
     *
     * Cleans preferences with an expiry date less than $asOf but not set to null (never expires).
     *
     * WARNING: You need to EntityManager::flush() if the return > 0!
     *
     * @param int|object|string $asOf default null A DateTime object or date(time) string or Unix timestamp for the expriy, null means now
     * @param string $name default null Limit it to the specified attributes, null means all attributes
     * @return int The number of preferences deleted
     */
    public function cleanExpiredPreferences( $asOf = null, $name = null )
    {
        $count = 0;

        if( $asOf === null )
            $asOf = new DateTime();
        elseif( is_int( $asOf ) )
            $asOf = new DateTime( date( 'Y-m-d H:i:s', $asOf ) );
        elseif( is_string( $asOf ) )
            $asOf = new DateTime( $asOf );

        foreach( $this->getPreferences() as $pref )
        {
            if( $name && ( $pref->getName() != $name ) )
                continue;

            if( $pref->getExpiresAt() && ( $pref->getExpiresAt() < $asOf ) )
            {
                $count++;
                $this->getPreferences()->removeElement( $pref );
                D2EM::remove( $pref );
            }
        }

        return $count;
    }


    /**
     * Delete the named preference
     *
     * WARNING: You need to EntityManager::flush() if the return > 0!
     *
     * @param string $name The named attribute / preference to check for
     * @param int $index default null If an indexed preference then delete a specific index, if null then delete all
     * @return int The number of preferences deleted
     */
    public function deletePreference( $name, $index = null )
    {
        $count = 0;

        foreach( $this->getPreferences() as $pref )
        {
            if( ( $pref->getName() == $name ) && ( ( $index === null ) || ( $pref->getIx() == $index ) ) )
            {
                $count++;
                $this->getPreferences()->removeElement( $pref );
                D2EM::remove( $pref );
            }
        }

        return $count;
    }


    /**
     * Deletes all preferences.
     *
     * @param void
     * @return int The number of preferences deleted
     */
    public function expungePreferences()
    {
        return D2EM::createQuery( 'delete \\Entities\\' . $this->_getPreferenceClassName() . ' up where up.' . $this->_getShortClassname() . ' = ?1' )
                     ->setParameter( 1, $this )
                     ->execute();
    }


    /**
     * Get indexed preferences as an array
     *
     * The standard response is an array of scalar values such as:
     *
     *     [ 'a', 'b', 'c' ];
     *
     * If $withIndex is set to true, then it will be an array of associated arrays with the
     * index included:
     *
     *     [
     *         [ 'p_index' => '0', 'p_value' => 'a' ],
     *         [ 'p_index' => '1', 'p_value' => 'b' ],
     *         [ 'p_index' => '2', 'p_value' => 'c' ]
     *     );
     *
     * @param string $name The attribute to load
     * @param boolean $withIndex default false Include index values.
     * @param boolean $ignoreExpired If set to false, include expired preferences
     * @return boolean|array False if no such preference(s) exist, otherwise an array.
     */
    public function getIndexedPreference( $name, $withIndex = false, $ignoreExpired = true )
    {
        $values = [];

        foreach( $this->getPreferences() as $pref )
        {
            if( $pref->getName() == $name )
            {
                if( $ignoreExpired && $pref->getExpiresAt() && ( $pref->getExpiresAt() < new DateTime() ) )
                    continue;

                if( $withIndex )
                    $values[ $pref->getIx() ] = [ 'index' => $pref->getIx(), 'value' => $pref->getValue() ];
                else
                    $values[ $pref->getIx() ] = $pref->getValue();
            }
        }

        if( $values === [] )
            return false;

        ksort( $values, SORT_NUMERIC );

        return $values;
    }


    /**
     * Get associative preferences as an array.
     *
     * For example, if we have preferences:
     *
     *     attribute email.address   idx=0 value=1email
     *     attribute email.confirmed idx=0 value=false
     *     attribute email.tokens.0  idx=0 value=fwfddwde
     *     attribute email.tokens.1  idx=0 value=fwewec4r
     *     attribute email.address   idx=1 value=2email
     *     attribute email.confirmed idx=1 value=true
     *
     * and if we search by `$name = 'email'` we will get:
     *
     *     [
     *         0 => [
     *             'address' => '1email',
     *             'confirmed' => false,
     *             'tokens' => [
     *                 0 => 'fwfddwde',
     *                 1 => 'fwewec4r'
     *             ]
     *         ],

     *         1 => [
     *             'address' => '2email',
     *             'confirmed' => true
     *         ]
     *     ]
     *
     *
     * @param string $name The attribute to load
     * @param int $index default null If an indexed preference, get a specific index, null means all indexes alowed
     * @param boolean $ignoreExpired default true If set to false, then includes expired preferences
     * @return boolean|array False if no such preference(s) exist, otherwise an array.
     */
    public function getAssocPreference( $name, $index = null, $ignoreExpired = true )
    {
        $values = [];

        foreach( $this->getPreferences() as $pref )
        {
            if( strpos( $pref->getName(), $name ) === 0 )
            {
                if( ( $index === null ) || ( $pref->getIx() == $index ) )
                {
                    if( !$ignoreExpired && $pref->getExpiresAt() && ( $pref->getExpiresAt() < new DateTime() ) )
                        continue;

                    if( strpos( $pref->getName(), '.' ) !== false )
                        $key = substr( $pref->getName(), strlen( $name ) + 1 );

                    if( $key )
                    {
                        $key = $pref->getIx() . '.' . $key;
                        $values = $this->_processKey( $values, $key, $pref->getValue() );
                    }
                    else
                    {
                        $values[ $pref->getIx() ] = $pref->getValue();
                    }
                }
            }
        }

        if( $values === [] )
            return false;

        return $values;
    }


    /**
     * Delete the named preference
     *
     * WARNING: You need to EntityManager::flush() if the return > 0!
     *
     * @param string $name The named attribute / preference to check for
     * @param int $index default null If an indexed preference then delete a specific index, if null then delete all
     * @return int The number of preferences deleted
     */
    public function deleteAssocPreference( $name, $index = null )
    {
        $count = 0;

        foreach( $this->getPreferences() as $pref )
        {
            if( ( strpos( $pref->getName(), $name ) === 0 ) && ( ( $index === null ) || ( $pref->getIx() == $index ) ) )
            {
                $this->getPreferences()->removeElement( $pref );
                D2EM::remove( $pref );
                $count++;
            }
        }

        return $count;
    }


    /**
     * Gets full class name. e.g. \Entities\User
     * It can be used when writing Doctrine2 queries.
     *
     * @return string
     */
    private function _getFullClassname()
    {
        if( $this->_fullClassName )
            return $this->_fullClassName;

        $this->_fullClassName = get_called_class();

        if( strpos( $this->_fullClassName, '__CG__' ) !== false )
            $this->_fullClassName = substr( $this->_fullClassName, strpos( $this->_fullClassName, '__CG__' ) + 6 );

        return $this->_fullClassName;
    }


    /**
     * Gets short class name. e.g. If full class name is \Entities\User then short name will be User.
     * It can be used when writing Doctrine2 queries.
     *
     * @return string
     */
    private function _getShortClassname()
    {
        if( $this->_shortClassName )
            return $this->_shortClassName;

        $this->_shortClassName = substr( $this->_getFullClassname(), strrpos( $this->_getFullClassname(), '\\' ) + 1 );

        return $this->_shortClassName;
    }


    private function _getPreferenceClassName()
    {
        if( $this->_preferenceClassName )
            return $this->_preferenceClassName;

        $this->_preferenceClassName = $this->_getShortClassname() . 'Preference';

        return $this->_preferenceClassName;
    }


    /**
     * Creates preference object.
     * New preference object depends current class. e.g. If we are extending
     * \Entities\Customer functionality then our preference object will be
     * \Entities\CustomerPreference.
     *
     * @return object
     */
    private function _createPreferenceEntity( $owner = null )
    {
        $prefClass = $this->_getFullClassname() . 'Preference';
        $pref = new $prefClass();

        if( $owner !== null )
        {
            $pref->{ 'set' . $this->_getShortClassname() }( $owner );
            $owner->{ 'add' . $this->_getPreferenceClassName() }( $pref );
        }

        return $pref;
    }


    /**
     * Assign the key's value to the property list. Handles the
     * nest separator for sub-properties.
     *
     * @param  array  $config
     * @param  string $key
     * @param  string $value
     * @throws Zend_Config_Exception
     * @return array
     */
    private function _processKey( $config, $key, $value )
    {
        if( strpos( $key, '.' ) !== false )
        {
            $pieces = explode( '.', $key, 2 );

            if( strlen( $pieces[0] ) && strlen( $pieces[1] ) )
            {
                if( !isset( $config[ $pieces[0] ] ) )
                {
                    if( ( $pieces[0] == '0' ) && !empty( $config ) )
                        $config = [ $pieces[0] => $config ];
                    else
                        $config[ $pieces[0] ] = [];
                }
                elseif( !is_array( $config[$pieces[0]] ) )
                {
                    //die( "Cannot create sub-key for '{$pieces[0]}' as key already exists" );
                }

                $config[ $pieces[0] ] = $this->_processKey( $config[ $pieces[0] ], $pieces[1], $value );
            }
            else
            {
                //die( "Invalid key '$key'" );
            }
        }
        else
        {
            $config[ $key ] = $value;
        }

        return $config;
    }

}
