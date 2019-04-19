<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_finder
 *
 * @copyright   Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Finder\Administrator\Indexer;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Object\CMSObject;
use Joomla\CMS\Profiler\Profiler;
use Joomla\String\StringHelper;

/**
 * Main indexer class for the Finder indexer package.
 *
 * The indexer class provides the core functionality of the Finder
 * search engine. It is responsible for adding and updating the
 * content links table; extracting and scoring tokens; and maintaining
 * all referential information for the content.
 *
 * Note: All exceptions thrown from within this class should be caught
 * by the controller.
 *
 * @since  2.5
 */
abstract class Indexer
{
	/**
	 * The title context identifier.
	 *
	 * @var    integer
	 * @since  2.5
	 */
	const TITLE_CONTEXT = 1;

	/**
	 * The text context identifier.
	 *
	 * @var    integer
	 * @since  2.5
	 */
	const TEXT_CONTEXT = 2;

	/**
	 * The meta context identifier.
	 *
	 * @var    integer
	 * @since  2.5
	 */
	const META_CONTEXT = 3;

	/**
	 * The path context identifier.
	 *
	 * @var    integer
	 * @since  2.5
	 */
	const PATH_CONTEXT = 4;

	/**
	 * The misc context identifier.
	 *
	 * @var    integer
	 * @since  2.5
	 */
	const MISC_CONTEXT = 5;

	/**
	 * The indexer state object.
	 *
	 * @var    CMSObject
	 * @since  2.5
	 */
	public static $state;

	/**
	 * The indexer profiler object.
	 *
	 * @var    Profiler
	 * @since  2.5
	 */
	public static $profiler;

	/**
	 * Database driver cache.
	 *
	 * @var    \Joomla\Database\DatabaseDriver
	 * @since  3.8.0
	 */
	protected $db;

	/**
	 * Reusable Query Template. To be used with clone.
	 *
	 * @var    Joomla\Database\QueryInterface
	 * @since  3.8.0
	 */
	protected $addTokensToDbQueryTemplate;

	/**
	 * FinderIndexer constructor.
	 *
	 * @since  3.8.0
	 */
	public function __construct()
	{
		$this->db = Factory::getDbo();

		$db = $this->db;

		// Set up query template for addTokensToDb
		$this->addTokensToDbQueryTemplate = $db->getQuery(true)->insert($db->quoteName('#__finder_tokens'))
			->columns(
				array(
					$db->quoteName('term'),
					$db->quoteName('stem'),
					$db->quoteName('common'),
					$db->quoteName('phrase'),
					$db->quoteName('weight'),
					$db->quoteName('context'),
					$db->quoteName('language')
				)
			);
	}

	/**
	 * Returns a reference to the FinderIndexer object.
	 *
	 * @return  Indexer instance based on the database driver
	 *
	 * @since   3.0
	 * @throws  RuntimeException if driver class for indexer not present.
	 */
	public static function getInstance()
	{
		// Setup the adapter for the indexer.
		$serverType = Factory::getDbo()->getServerType();

		$class = '\\Joomla\\Component\\Finder\\Administrator\\Indexer\\Driver\\' . ucfirst($serverType);

		// Check if a parser exists for the format.
		if (class_exists($class))
		{
			return new $class;
		}

		// Throw invalid format exception.
		throw new \RuntimeException(Text::sprintf('COM_FINDER_INDEXER_INVALID_DRIVER', $serverType));
	}

	/**
	 * Method to get the indexer state.
	 *
	 * @return  object  The indexer state object.
	 *
	 * @since   2.5
	 */
	public static function getState()
	{
		// First, try to load from the internal state.
		if ((bool) static::$state)
		{
			return static::$state;
		}

		// If we couldn't load from the internal state, try the session.
		$session = Factory::getSession();
		$data = $session->get('_finder.state', null);

		// If the state is empty, load the values for the first time.
		if (empty($data))
		{
			$data = new CMSObject;

			// Load the default configuration options.
			$data->options = ComponentHelper::getParams('com_finder');

			// Setup the weight lookup information.
			$data->weights = array(
				self::TITLE_CONTEXT => round($data->options->get('title_multiplier', 1.7), 2),
				self::TEXT_CONTEXT  => round($data->options->get('text_multiplier', 0.7), 2),
				self::META_CONTEXT  => round($data->options->get('meta_multiplier', 1.2), 2),
				self::PATH_CONTEXT  => round($data->options->get('path_multiplier', 2.0), 2),
				self::MISC_CONTEXT  => round($data->options->get('misc_multiplier', 0.3), 2)
			);

			// Set the current time as the start time.
			$data->startTime = Factory::getDate()->toSql();

			// Set the remaining default values.
			$data->batchSize   = (int) $data->options->get('batch_size', 50);
			$data->batchOffset = 0;
			$data->totalItems  = 0;
			$data->pluginState = array();
		}

		// Setup the profiler if debugging is enabled.
		if (Factory::getApplication()->get('debug'))
		{
			static::$profiler = Profiler::getInstance('FinderIndexer');
		}

		// Set the state.
		static::$state = $data;

		return static::$state;
	}

	/**
	 * Method to set the indexer state.
	 *
	 * @param   CMSObject  $data  A new indexer state object.
	 *
	 * @return  boolean  True on success, false on failure.
	 *
	 * @since   2.5
	 */
	public static function setState($data)
	{
		// Check the state object.
		if (empty($data) || !$data instanceof CMSObject)
		{
			return false;
		}

		// Set the new internal state.
		static::$state = $data;

		// Set the new session state.
		Factory::getSession()->set('_finder.state', $data);

		return true;
	}

	/**
	 * Method to reset the indexer state.
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	public static function resetState()
	{
		// Reset the internal state to null.
		self::$state = null;

		// Reset the session state to null.
		Factory::getSession()->set('_finder.state', null);
	}

	/**
	 * Method to index a content item.
	 *
	 * @param   Result  $item    The content item to index.
	 * @param   string  $format  The format of the content. [optional]
	 *
	 * @return  integer  The ID of the record in the links table.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	abstract public function index($item, $format = 'html');

	/**
	 * Method to remove a link from the index.
	 *
	 * @param   integer  $linkId  The id of the link.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	public function remove($linkId)
	{
		$db    = $this->db;
		$query = $db->getQuery(true);

		// Update the link counts for the terms.
		$query->clear()
			->update($db->quoteName('#__finder_terms', 't'))
			->join('INNER', $db->quoteName('#__finder_links_terms', 'm') . ' ON ' . $db->quoteName('m.term_id') . ' = ' . $db->quoteName('t.term_id'))
			->set($db->quoteName('links') . ' = ' . $db->quoteName('links') . ' - 1')
			->where($db->quoteName('m.link_id') . ' = ' . (int) $linkId);
		$db->setQuery($query)->execute();

		// Remove all records from the mapping tables.
		$query->clear()
			->delete($db->quoteName('#__finder_links_terms'))
			->where($db->quoteName('link_id') . ' = ' . (int) $linkId);
		$db->setQuery($query)->execute();

		// Delete all orphaned terms.
		$query->clear()
			->delete($db->quoteName('#__finder_terms'))
			->where($db->quoteName('links') . ' <= 0');
		$db->setQuery($query)->execute();

		// Delete the link from the index.
		$query->clear()
			->delete($db->quoteName('#__finder_links'))
			->where($db->quoteName('link_id') . ' = ' . (int) $linkId);
		$db->setQuery($query)->execute();

		// Remove the taxonomy maps.
		Taxonomy::removeMaps($linkId);

		// Remove the orphaned taxonomy nodes.
		Taxonomy::removeOrphanNodes();

		return true;
	}

	/**
	 * Method to optimize the index. We use this method to remove unused terms
	 * and any other optimizations that might be necessary.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	abstract public function optimize();

	/**
	 * Method to get a content item's signature.
	 *
	 * @param   object  $item  The content item to index.
	 *
	 * @return  string  The content item's signature.
	 *
	 * @since   2.5
	 */
	protected static function getSignature($item)
	{
		// Get the indexer state.
		$state = static::getState();

		// Get the relevant configuration variables.
		$config = array(
			$state->weights,
			$state->options->get('stem', 1),
			$state->options->get('stemmer', 'porter_en')
		);

		return md5(serialize(array($item, $config)));
	}

	/**
	 * Method to parse input, tokenize it, and then add it to the database.
	 *
	 * @param   mixed    $input    String or resource to use as input. A resource input will automatically be chunked to conserve
	 *                             memory. Strings will be chunked if longer than 2K in size.
	 * @param   integer  $context  The context of the input. See context constants.
	 * @param   string   $lang     The language of the input.
	 * @param   string   $format   The format of the input.
	 *
	 * @return  integer  The number of tokens extracted from the input.
	 *
	 * @since   2.5
	 */
	protected function tokenizeToDb($input, $context, $lang, $format)
	{
		$count = 0;
		$buffer = null;

		if (empty($input))
		{
			return $count;
		}

		// If the input is a resource, batch the process out.
		if (is_resource($input))
		{
			// Batch the process out to avoid memory limits.
			while (!feof($input))
			{
				// Read into the buffer.
				$buffer .= fread($input, 2048);

				/*
				 * If we haven't reached the end of the file, seek to the last
				 * space character and drop whatever is after that to make sure
				 * we didn't truncate a term while reading the input.
				 */
				if (!feof($input))
				{
					// Find the last space character.
					$ls = strrpos($buffer, ' ');

					// Adjust string based on the last space character.
					if ($ls)
					{
						// Truncate the string to the last space character.
						$string = substr($buffer, 0, $ls);

						// Adjust the buffer based on the last space for the next iteration and trim.
						$buffer = StringHelper::trim(substr($buffer, $ls));
					}
					// No space character was found.
					else
					{
						$string = $buffer;
					}
				}
				// We've reached the end of the file, so parse whatever remains.
				else
				{
					$string = $buffer;
				}

				// Parse, tokenise and add tokens to the database.
				$count = $this->tokenizeToDbShort($string, $context, $lang, $format, $count);

				unset($string, $tokens);
			}

			return $count;
		}

		// Parse, tokenise and add tokens to the database.
		$count = $this->tokenizeToDbShort($input, $context, $lang, $format, $count);

		return $count;
	}

	/**
	 * Method to parse input, tokenise it, then add the tokens to the database.
	 *
	 * @param   string   $input    String to parse, tokenise and add to database.
	 * @param   integer  $context  The context of the input. See context constants.
	 * @param   string   $lang     The language of the input.
	 * @param   string   $format   The format of the input.
	 * @param   integer  $count    The number of tokens processed so far.
	 *
	 * @return  integer  Cumulative number of tokens extracted from the input so far.
	 *
	 * @since   3.7.0
	 */
	private function tokenizeToDbShort($input, $context, $lang, $format, $count)
	{
		// Parse the input.
		$input = Helper::parse($input, $format);

		// Check the input.
		if (empty($input))
		{
			return $count;
		}

		// Tokenize the input.
		$tokens = Helper::tokenize($input, $lang);

		if (count($tokens) == 0)
		{
			return $count;
		}

		// Add the tokens to the database.
		$count += $this->addTokensToDb($tokens, $context);

		// Check if we're approaching the memory limit of the token table.
		if ($count > static::$state->options->get('memory_table_limit', 30000))
		{
			$this->toggleTables(false);
		}

		return $count;
	}

	/**
	 * Method to add a set of tokens to the database.
	 *
	 * @param   Token[]|Token  $tokens   An array or single Token object.
	 * @param   mixed          $context  The context of the tokens. See context constants. [optional]
	 *
	 * @return  integer  The number of tokens inserted into the database.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	protected function addTokensToDb($tokens, $context = '')
	{
		static $filterCommon, $filterNumeric;

		if (is_null($filterCommon))
		{
			$params = ComponentHelper::getParams('com_finder');
			$filterCommon = $params->get('filter_commonwords', false);
			$filterNumeric = $params->get('filter_numerics', false);
		}

		// Get the database object.
		$db = $this->db;

		$query = clone $this->addTokensToDbQueryTemplate;

		// Count the number of token values.
		$values = 0;

		// Iterate through the tokens to create SQL value sets.
		if (!is_a($tokens, Token::class))
		{
			foreach ($tokens as $token)
			{
				if ($filterCommon && $token->common)
				{
					continue;
				}

				if ($filterNumeric && $token->numeric)
				{
					continue;
				}

				$query->values(
					$db->quote($token->term) . ', '
					. $db->quote($token->stem) . ', '
					. (int) $token->common . ', '
					. (int) $token->phrase . ', '
					. $db->quote($token->weight) . ', '
					. (int) $context . ', '
					. $db->quote($token->language)
				);
				$values++;
			}
		}
		else
		{
			$query->values(
				$db->quote($tokens->term) . ', '
				. $db->quote($tokens->stem) . ', '
				. (int) $tokens->common . ', '
				. (int) $tokens->phrase . ', '
				. $db->escape((float) $tokens->weight) . ', '
				. (int) $context . ', '
				. $db->quote($tokens->language)
			);
			$values++;
		}

		if ($query->values)
		{
			$db->setQuery($query)->execute();
		}

		return $values;
	}

	/**
	 * Method to switch the token tables from Memory tables to Disk tables
	 * when they are close to running out of memory.
	 * Since this is not supported/implemented in all DB-drivers, the default is a stub method, which simply returns true.
	 *
	 * @param   boolean  $memory  Flag to control how they should be toggled.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	protected function toggleTables($memory)
	{
		return true;
	}
}
