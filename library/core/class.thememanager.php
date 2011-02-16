<?php if (!defined('APPLICATION')) exit();
/*
Copyright 2008, 2009 Vanilla Forums Inc.
This file is part of Garden.
Garden is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
Garden is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with Garden.  If not, see <http://www.gnu.org/licenses/>.
Contact Vanilla Forums Inc. at support [at] vanillaforums [dot] com
*/

/**
 * Garden.Core
 */

/**
 * Manages available themes, enabling and disabling them.
 */
class Gdn_ThemeManager {
   
   /**
    * An array of search paths for themes and their files
    */
   protected $ThemeSearchPaths = NULL;
   
   /**
    * An array of available plugins. Never access this directly, instead use
    * $this->AvailablePlugins();
    */
   protected $ThemeCache = NULL;
   
   public function __construct() {
      $this->ThemeSearchPaths = array();
      
      // Add default search path(s) to list
      $this->ThemeSearchPaths[rtrim(PATH_LOCAL_THEMES,'/')] = 'local';
      $this->ThemeSearchPaths[rtrim(PATH_THEMES,'/')] = 'core';
      
      // Check for, and load, alternate search paths from config
      $AlternatePaths = C('Garden.ThemeManager.Search', NULL);
      if (is_null($AlternatePaths)) return;
      
      if (!is_array($AlternatePaths))
         $AlternatePaths = array($AlternatePaths => 'alternate');
      
      foreach ($AlternatePaths as $AltPath => $AltName)
         if (is_dir($AltPath))
            $this->ThemeSearchPaths[rtrim($AltPath, '/')] = $AltName;
   }
   
   /**
    * Sets up the theme framework
    *
    * This method indexes all available themes and extracts their information.
    * It then determines which plugins have been enabled, and includes them.
    * Finally, it parses all plugin files and extracts their events and plugged
    * methods.
    */
   public function Start($Force = FALSE) {
      
      // Build list of all available themes
      $this->AvailableThemes($Force);
      
      // If there is a hooks file in the theme folder, include it.
      $ThemeName = $this->CurrentTheme();
      $ThemeInfo = $this->GetThemeInfo($ThemeName);
      $ThemeHooks = GetValue('RealHooksFile', $ThemeInfo, NULL);
      if (file_exists($ThemeHooks))
         include_once($ThemeHooks);
      
   }
   
   /**
    * Looks through the themes directory for valid themes and returns them as
    * an associative array of "Theme Name" => "Theme Info Array". It also adds
    * a "Folder" definition to the Theme Info Array for each.
    */
   public function AvailableThemes($Force = FALSE) {
      
      if (is_null($this->ThemeCache) || $Force) {
      
         $this->ThemeCache = array();
         
         // Check cache freshness
         foreach ($this->ThemeSearchPaths as $SearchPath => $Trash) {
            unset($SearchPathCache);
            
            // Check Cache
            $SearchPathCacheKey = 'Garden.Themes.PathCache.'.$SearchPath;
            $SearchPathCache = Gdn::Cache()->Get($SearchPathCacheKey);
            
            $CacheHit = ($SearchPathCache !== Gdn_Cache::CACHEOP_FAILURE);
            if ($CacheHit && is_array($SearchPathCache)) {
               $CacheIntegrityCheck = (sizeof(array_intersect(array_keys($SearchPathCache), array('CacheIntegrityHash', 'ThemeInfo'))) == 2);
               if (!$CacheIntegrityCheck) {
                  $SearchPathCache = array(
                     'CacheIntegrityHash'    => NULL,
                     'ThemeInfo'             => array()
                  );
               }
            }
            
            $CacheThemeInfo = &$SearchPathCache['ThemeInfo'];
            if (!is_array($CacheThemeInfo))
               $CacheThemeInfo = array();
            
            $PathListing = scandir($SearchPath, 0);
            sort($PathListing);
            
            $PathIntegrityHash = md5(serialize($PathListing));
            if (GetValue('CacheIntegrityHash',$SearchPathCache) != $PathIntegrityHash) {
               // Need to re-index this folder
               $PathIntegrityHash = $this->IndexSearchPath($SearchPath, $CacheThemeInfo, $PathListing);
               if ($PathIntegrityHash === FALSE)
                  continue;
               
               $SearchPathCache['CacheIntegrityHash'] = $PathIntegrityHash;
               Gdn::Cache()->Store($SearchPathCacheKey, $SearchPathCache);
            }
            
            $this->ThemeCache = array_merge($this->ThemeCache, $CacheThemeInfo);
         }
      }
            
      return $this->ThemeCache;
   }
   
   public function IndexSearchPath($SearchPath, &$ThemeInfo, $PathListing = NULL) {
      if (is_null($PathListing) || !is_array($PathListing)) {
         $PathListing = scandir($SearchPath, 0);
         sort($PathListing);
      }
      
      if ($PathListing === FALSE)
         return FALSE;
      
      foreach ($PathListing as $ThemeFolderName) {
         if (substr($ThemeFolderName, 0, 1) == '.')
            continue;
         
         $ThemePath = CombinePaths(array($SearchPath,$ThemeFolderName));
         $ThemeFiles = $this->FindThemeFiles($ThemePath);
         
         if (GetValue('about', $ThemeFiles) === FALSE)
            continue;
            
         $ThemeAboutFile = GetValue('about', $ThemeFiles);
         $SearchThemeInfo = $this->ScanThemeFile($ThemeAboutFile);
         
         // Add the screenshot.
         if (array_key_exists('screenshot', $ThemeFiles)) {
            $RelativeScreenshot = ltrim(str_replace(PATH_ROOT, '', GetValue('screenshot', $ThemeFiles)),'/');
            $SearchThemeInfo['ScreenshotUrl'] = Asset($RelativeScreenshot, TRUE);
         }
            
         if (array_key_exists('hooks', $ThemeFiles)) {
            $SearchThemeInfo['HooksFile'] = GetValue('hooks', $ThemeFiles, FALSE);
            $SearchThemeInfo['RealHooksFile'] = realpath($SearchThemeInfo['HooksFile']);
         }
         
         if ($SearchThemeInfo === FALSE)
            continue;
         
         $ThemeInfo[$ThemeFolderName] = $SearchThemeInfo;
      }
      
      return md5(serialize($PathListing));
   }
   
   public function FindThemeFiles($ThemePath) {
      if (!is_dir($ThemePath))
         return FALSE;
      
      $ThemeFiles = scandir($ThemePath);
      $TestPatterns = array(
         'about\.php'                           => 'about',
         '.*\.theme\.php'                       => 'about',
         'class\..*themehooks\.php'             => 'hooks',
         'screenshot\.(gif|jpg|jpeg|png)'       => 'screenshot'
      );
      
      $MatchedThemeFiles = array();
      foreach ($ThemeFiles as $ThemeFile) {
         foreach ($TestPatterns as $TestPattern => $FileType) {
            if (preg_match('!'.$TestPattern.'!', $ThemeFile))
               $MatchedThemeFiles[$FileType] = CombinePaths(array($ThemePath, $ThemeFile));
         }
      }
      
      return array_key_exists('about', $MatchedThemeFiles) ? $MatchedThemeFiles : FALSE;
   }
   
   public function ScanThemeFile($ThemeFile, $VariableName = NULL) {
      // Find the $PluginInfo array
      if (!file_exists($ThemeFile)) return;
      $Lines = file($ThemeFile);
      
      $InfoBuffer = FALSE;
      $ClassBuffer = FALSE;
      $ClassName = '';
      $ThemeInfoString = '';
      if (!$VariableName)
         $VariableName = 'ThemeInfo';
      
      $ParseVariableName = '$'.$VariableName;
      ${$VariableName} = array();

      foreach ($Lines as $Line) {
         if ($InfoBuffer && substr(trim($Line), -2) == ');') {
            $ThemeInfoString .= $Line;
            $ClassBuffer = TRUE;
            $InfoBuffer = FALSE;
         }
         
         if (StringBeginsWith(trim($Line), $ParseVariableName))
            $InfoBuffer = TRUE;
            
         if ($InfoBuffer)
            $ThemeInfoString .= $Line;
            
         if ($ClassBuffer && strtolower(substr(trim($Line), 0, 6)) == 'class ') {
            $Parts = explode(' ', $Line);
            if (count($Parts) > 2)
               $ClassName = $Parts[1];
               
            break;
         }
         
      }
      unset($Lines);
      if ($ThemeInfoString != '')
         @eval($ThemeInfoString);
         
      // Define the folder name and assign the class name for the newly added item
      if (isset(${$VariableName}) && is_array(${$VariableName})) {
         $Item = array_pop($Trash = array_keys(${$VariableName}));
         
         ${$VariableName}[$Item]['Index'] = $Item;
         ${$VariableName}[$Item]['AboutFile'] = $ThemeFile;
         ${$VariableName}[$Item]['RealAboutFile'] = realpath($ThemeFile);
         ${$VariableName}[$Item]['ThemeRoot'] = dirname($ThemeFile);
         
         if (!array_key_exists('Name', ${$VariableName}[$Item]))
            ${$VariableName}[$Item]['Name'] = $Item;
            
         if (!array_key_exists('Folder', ${$VariableName}[$Item]))
            ${$VariableName}[$Item]['Folder'] = $Item;
         
         return ${$VariableName}[$Item];
      } elseif ($VariableName !== NULL) {
         if (isset(${$VariableName}))
            return ${$VariableName};
      }
      
      return NULL;
   }
   
   public function GetThemeInfo($ThemeName) {
      return GetValue($ThemeName, $this->AvailableThemes(), FALSE);
   }

   public function CurrentTheme() {
      return C(!IsMobile() ? 'Garden.Theme' : 'Garden.MobileTheme', 'default');
   }

   public function DisableTheme() {
      if ($this->CurrentTheme() == 'default') {
         throw new Gdn_UserException(T('You cannot disable the default theme.'));
      }
      RemoveFromConfig('Garden.Theme');
   }
   
   public function EnabledTheme() {
      $ThemeFolder = Gdn::Config('Garden.Theme', 'default');
      return $ThemeFolder;
   }
   
   public function EnabledThemeInfo($ReturnInSourceFormat = FALSE) {
      $AvailableThemes = $this->AvailableThemes();
      $ThemeFolder = $this->EnabledTheme();
      foreach ($AvailableThemes as $ThemeName => $ThemeInfo) {
         if (ArrayValue('Folder', $ThemeInfo, '') == $ThemeFolder) {
            $Info = $ReturnInSourceFormat ? array($ThemeName => $ThemeInfo) : $ThemeInfo;
            // Update the theme info for a format consumable by views.
            if (is_array($Info) & isset($Info['Options'])) {
               $Options =& $Info['Options'];
               if (isset($Options['Styles'])) {
                  foreach ($Options['Styles'] as $Key => $Params) {
                     if (is_string($Params)) {
                        $Options['Styles'][$Key] = array('Basename' => $Params);
                     } elseif (is_array($Params) && isset($Params[0])) {
                        $Params['Basename'] = $Params[0];
                        unset($Params[0]);
                        $Options['Styles'][$Key] = $Params;
                     }
                  }
               }
               if (isset($Options['Text'])) {
                  foreach ($Options['Text'] as $Key => $Params) {
                     if (is_string($Params)) {
                        $Options['Text'][$Key] = array('Type' => $Params);
                     } elseif (is_array($Params) && isset($Params[0])) {
                        $Params['Type'] = $Params[0];
                        unset($Params[0]);
                        $Options['Text'][$Key] = $Params;
                     }
                  }
               }
            }
            return $Info;
         }

      }
      return array();
   }
   
   public function EnableTheme($ThemeName) {
      // Make sure to run the setup
      $this->TestTheme($ThemeName);
      
      // Set the theme.
      $ThemeInfo = ArrayValueI($ThemeName, $this->AvailableThemes(), array());
      $ThemeName = GetValue('Index', $ThemeInfo, $ThemeName);
      $ThemeFolder = GetValue('Folder', $ThemeInfo, '');
      if ($ThemeFolder == '') {
         throw new Exception(T('The theme folder was not properly defined.'));
      } else {
         $Options = GetValueR("$ThemeName.Options", $this->AvailableThemes());
         if ($Options) {
            SaveToConfig(array(
               'Garden.Theme' => $ThemeFolder,
               'Garden.ThemeOptions.Name' => GetValueR("$ThemeName.Name", $this->AvailableThemes(), $ThemeFolder)));
         } else {
            SaveToConfig('Garden.Theme', $ThemeFolder);
            RemoveFromConfig('Garden.ThemeOptions');
         }
      }

      // Tell the locale cache to refresh itself.
      $ApplicationManager = new Gdn_ApplicationManager();
      Gdn::Locale()->Refresh();
      return TRUE;
   }
   
   public function TestTheme($ThemeName) {
      // Get some info about the currently enabled theme.
      $EnabledTheme = $this->EnabledThemeInfo();
      $EnabledThemeFolder = GetValue('Folder', $EnabledTheme, '');
      $OldClassName = $EnabledThemeFolder . 'ThemeHooks';
      
      // Make sure that the theme's requirements are met
      $ApplicationManager = new Gdn_ApplicationManager();
      $EnabledApplications = $ApplicationManager->EnabledApplications();
      $AvailableThemes = $this->AvailableThemes();
      $NewThemeInfo = ArrayValueI($ThemeName, $AvailableThemes, array());
      $ThemeName = GetValue('Index', $NewThemeInfo, $ThemeName);
      $RequiredApplications = ArrayValue('RequiredApplications', $NewThemeInfo, FALSE);
      $ThemeFolder = ArrayValue('Folder', $NewThemeInfo, '');
      CheckRequirements($ThemeName, $RequiredApplications, $EnabledApplications, 'application'); // Applications

      // If there is a hooks file, include it and run the setup method.
      $ClassName = $ThemeFolder . 'ThemeHooks';
      $HooksFile = PATH_THEMES . DS . $ThemeFolder . DS . 'class.' . strtolower($ClassName) . '.php';
      if (file_exists($HooksFile)) {
         include_once($HooksFile);
         if (class_exists($ClassName)) {
            $ThemeHooks = new $ClassName();
            $ThemeHooks->Setup();
         }
      }

      // If there is a hooks in the old theme, include it and run the ondisable method.
      if (class_exists($OldClassName)) {
         $ThemeHooks = new $OldClassName();
         if (method_exists($ThemeHooks, 'OnDisable')) {
            $ThemeHooks->OnDisable();
         }
      }

      return TRUE;
   }
}
