/**
 * InfDB Client Plugin
 * Uses SteamWorks HTTP - no system2 dependency
 */

#pragma semicolon 1
#pragma newdecls required

#include <sourcemod>
#include <shavit>
#include <shavit/wr>
#include <SteamWorks>
#include <clientprefs>

#define PLUGIN_VERSION "1.2.0"
#define MAX_MAP_LEN    128
#define WR_POLL_INTERVAL 10.0

ConVar g_cvAPIURL;
ConVar g_cvAPIKey;
ConVar g_cvEnabled;
ConVar g_cvStyleFilter;

int  g_iShowMode[MAXPLAYERS+1];
int  g_iAbbrev[MAXPLAYERS+1];

char g_sCurrentMap[MAX_MAP_LEN];
char g_sWRTime[32];
char g_sWRPlayer[64];
bool g_bWRLoaded;
bool g_bInfiniteStyle[256];

Handle g_hPrefShowMode;
Handle g_hPrefAbbrev;
Handle g_hPollTimer;

static const char ABBREV_NAMES[3][] = { "InfDB", "InfiniDB", "IDB" };

public Plugin myinfo = {
    name        = "InfDB",
    author      = "luna",
    description = "InfDB time submission and HUD",
    version     = PLUGIN_VERSION,
    url         = "https://infdb.lunae.pw"
};

public void OnPluginStart() {
    g_cvAPIURL      = CreateConVar("infdb_api_url",      "https://infdb.lunae.pw/api", "InfDB API base URL");
    g_cvAPIKey      = CreateConVar("infdb_api_key",      "", "Your server API key", FCVAR_PROTECTED);
    g_cvEnabled     = CreateConVar("infdb_enabled",      "1", "Enable InfDB plugin");
    g_cvStyleFilter = CreateConVar("infdb_style_filter", "Infinite", "Substring to match style names for submission (case-insensitive)");

    AutoExecConfig(true, "infdb");

    g_hPrefShowMode = RegClientCookie("infdb_showmode", "InfDB HUD show mode", CookieAccess_Protected);
    g_hPrefAbbrev   = RegClientCookie("infdb_abbrev",   "InfDB abbreviation",  CookieAccess_Protected);

    RegConsoleCmd("sm_infdb",     Cmd_InfDB, "Open InfDB settings menu");
    RegConsoleCmd("sm_infdbmenu", Cmd_InfDB, "Open InfDB settings menu");
    RegAdminCmd("sm_infdb_test", Cmd_Test, ADMFLAG_ROOT, "Test InfDB API submission");

    CreateTimer(1.0, Timer_BuildStyles);
}

public Action Timer_BuildStyles(Handle timer) {
    BuildStyleList();
    return Plugin_Stop;
}

void BuildStyleList() {
    char filter[64], name[64];
    g_cvStyleFilter.GetString(filter, sizeof(filter));
    int count = Shavit_GetStyleCount();
    for (int i = 0; i < count && i < 256; i++) {
        Shavit_GetStyleStrings(i, sStyleName, name, sizeof(name));
        g_bInfiniteStyle[i] = (StrContains(name, filter, false) != -1);
        if (g_bInfiniteStyle[i])
            LogMessage("[InfDB] Tracking style %d: %s", i, name);
    }
}

bool IsInfiniteStyle(int style) {
    return (style >= 0 && style < 256 && g_bInfiniteStyle[style]);
}

public void OnMapStart() {
    g_bWRLoaded = false;
    strcopy(g_sWRTime,   sizeof(g_sWRTime),   "N/A");
    strcopy(g_sWRPlayer, sizeof(g_sWRPlayer),  "none");

    GetCurrentMap(g_sCurrentMap, sizeof(g_sCurrentMap));
    int sep = FindCharInString(g_sCurrentMap, '/', true);
    if (sep != -1)
        strcopy(g_sCurrentMap, sizeof(g_sCurrentMap), g_sCurrentMap[sep+1]);

    BuildStyleList();
    FetchWR();

    if (g_hPollTimer != INVALID_HANDLE) {
        KillTimer(g_hPollTimer);
        g_hPollTimer = INVALID_HANDLE;
    }
    g_hPollTimer = CreateTimer(WR_POLL_INTERVAL, Timer_PollWR, _, TIMER_REPEAT | TIMER_FLAG_NO_MAPCHANGE);
}

public void OnMapEnd() {
    if (g_hPollTimer != INVALID_HANDLE) {
        KillTimer(g_hPollTimer);
        g_hPollTimer = INVALID_HANDLE;
    }
}

public Action Timer_PollWR(Handle timer) {
    FetchWR();
    return Plugin_Continue;
}

public void OnClientPutInServer(int client) {
    g_iShowMode[client] = 0;
    g_iAbbrev[client]   = 0;
}

public void OnClientCookiesCached(int client) {
    char buf[8];
    GetClientCookie(client, g_hPrefShowMode, buf, sizeof(buf));
    g_iShowMode[client] = buf[0] ? StringToInt(buf) : 0;
    GetClientCookie(client, g_hPrefAbbrev, buf, sizeof(buf));
    g_iAbbrev[client] = buf[0] ? StringToInt(buf) : 0;
}

public void Shavit_OnFinish_Post(int client, int style, float time, int jumps, int strafes, float sync, int rank, int overwrite, int track, float oldtime, float perfs, float avgvel, float maxvel, int timestamp) {
    if (!g_cvEnabled.BoolValue) return;
    LogMessage("[InfDB] OnFinish fired: style=%d isInfinite=%d", style, IsInfiniteStyle(style) ? 1 : 0);
    if (!IsInfiniteStyle(style)) return;

    char apiKey[128];
    g_cvAPIKey.GetString(apiKey, sizeof(apiKey));
    if (!apiKey[0]) {
        LogError("[InfDB] infdb_api_key is not set!");
        return;
    }

    char steamid[32], name[64];
    GetClientAuthId(client, AuthId_Steam2, steamid, sizeof(steamid));
    GetClientName(client, name, sizeof(name));

    int time_ms = RoundToFloor(time * 1000.0);

    char apiURL[256];
    g_cvAPIURL.GetString(apiURL, sizeof(apiURL));

    char safeName[128];
    strcopy(safeName, sizeof(safeName), name);
    ReplaceString(safeName, sizeof(safeName), "\"", "\\\"");

    char json[512];
    FormatEx(json, sizeof(json),
        "{\"api_key\":\"%s\",\"steamid\":\"%s\",\"name\":\"%s\",\"map\":\"%s\",\"style\":%d,\"track\":%d,\"time_ms\":%d}",
        apiKey, steamid, safeName, g_sCurrentMap, style, track, time_ms
    );

    char submitURL[512];
    FormatEx(submitURL, sizeof(submitURL), "%s/submit.php", apiURL);

    Handle req = SteamWorks_CreateHTTPRequest(k_EHTTPMethodPOST, submitURL);
    if (req == INVALID_HANDLE) {
        LogError("[InfDB] Failed to create HTTP request");
        return;
    }

    SteamWorks_SetHTTPRequestRawPostBody(req, "application/json", json, strlen(json));
    SteamWorks_SetHTTPCallbacks(req, OnSubmitCompleted);
    SteamWorks_SendHTTPRequest(req);
}

public void OnSubmitCompleted(Handle hRequest, bool bFailure, bool bRequestSuccessful, EHTTPStatusCode eStatusCode) {
    if (bFailure || !bRequestSuccessful) {
        LogError("[InfDB] Submit request failed (status %d)", eStatusCode);
        CloseHandle(hRequest);
        return;
    }
    SteamWorks_GetHTTPResponseBodyCallback(hRequest, OnSubmitBody);
    CloseHandle(hRequest);
}

public void OnSubmitBody(const char[] sBody) {
    if (StrContains(sBody, "\"is_wr\":true") != -1) {
        FetchWR();
    }
}

void FetchWR() {
    if (!g_cvEnabled.BoolValue) return;

    char apiURL[256];
    g_cvAPIURL.GetString(apiURL, sizeof(apiURL));

    char url[512];
    FormatEx(url, sizeof(url), "%s/wr.php?map=%s&style=12&track=0", apiURL, g_sCurrentMap);

    Handle req = SteamWorks_CreateHTTPRequest(k_EHTTPMethodGET, url);
    if (req == INVALID_HANDLE) {
        LogError("[InfDB] Failed to create WR fetch request");
        return;
    }

    SteamWorks_SetHTTPCallbacks(req, OnWRCompleted);
    SteamWorks_SendHTTPRequest(req);
}

public void OnWRCompleted(Handle hRequest, bool bFailure, bool bRequestSuccessful, EHTTPStatusCode eStatusCode) {
    if (bFailure || !bRequestSuccessful) {
        LogError("[InfDB] WR fetch failed");
        CloseHandle(hRequest);
        return;
    }
    SteamWorks_GetHTTPResponseBodyCallback(hRequest, OnWRBody);
    CloseHandle(hRequest);
}

public void OnWRBody(const char[] sBody) {
    if (StrContains(sBody, "\"error\"") != -1) {
        strcopy(g_sWRTime,   sizeof(g_sWRTime),   "N/A");
        strcopy(g_sWRPlayer, sizeof(g_sWRPlayer),  "none");
        g_bWRLoaded = true;
        return;
    }

    int tp = StrContains(sBody, "\"time\":\"");
    if (tp != -1) {
        tp += 8;
        char tmp[32];
        strcopy(tmp, sizeof(tmp), sBody[tp]);
        int end = FindCharInString(tmp, '"');
        if (end != -1) {
            tmp[end] = '\0';
            strcopy(g_sWRTime, sizeof(g_sWRTime), tmp);
        }
    }

    int pp = StrContains(sBody, "\"player\":\"");
    if (pp != -1) {
        pp += 10;
        char tmp[64];
        strcopy(tmp, sizeof(tmp), sBody[pp]);
        int end = FindCharInString(tmp, '"');
        if (end != -1) {
            tmp[end] = '\0';
            strcopy(g_sWRPlayer, sizeof(g_sWRPlayer), tmp);
        }
    }

    g_bWRLoaded = true;
}

public Action Shavit_OnTopLeftHUD(int client, int target, char[] message, int maxlen, int track, int style) {
    if (!g_cvEnabled.BoolValue) return Plugin_Continue;
    if (!g_bWRLoaded) return Plugin_Continue;

    if (g_iShowMode[client] == 1 && !IsInfiniteStyle(Shavit_GetBhopStyle(client)))
        return Plugin_Continue;

    char abbrev[16];
    strcopy(abbrev, sizeof(abbrev), ABBREV_NAMES[g_iAbbrev[client]]);

    Format(message, maxlen, "%s\n%s: %s (%s)", message, abbrev, g_sWRTime, g_sWRPlayer);
    return Plugin_Changed;
}


public Action Cmd_Test(int client, int args) {
    char apiKey[128];
    g_cvAPIKey.GetString(apiKey, sizeof(apiKey));
    PrintToServer("[InfDB] API URL: %s", "https://infdb.lunae.pw/api");
    PrintToServer("[InfDB] API key set: %s", apiKey[0] ? "YES" : "NO");
    PrintToServer("[InfDB] Current map: %s", g_sCurrentMap);
    PrintToServer("[InfDB] WR loaded: %s", g_bWRLoaded ? "YES" : "NO");
    PrintToServer("[InfDB] WR time: %s (%s)", g_sWRTime, g_sWRPlayer);
    PrintToServer("[InfDB] Style 12 is Infinite: %s", g_bInfiniteStyle[12] ? "YES" : "NO");
    PrintToServer("[InfDB] Sending test submission...");

    char json[512];
    FormatEx(json, sizeof(json),
        "{\"api_key\":\"%s\",\"steamid\":\"STEAM_1:0:000000000\",\"name\":\"[InfDB Test]\",\"map\":\"%s\",\"style\":12,\"track\":0,\"time_ms\":99999999}",
        apiKey, g_sCurrentMap
    );

    char apiURL[256];
    g_cvAPIURL.GetString(apiURL, sizeof(apiURL));
    char submitURL[512];
    FormatEx(submitURL, sizeof(submitURL), "%s/submit.php", apiURL);

    Handle req = SteamWorks_CreateHTTPRequest(k_EHTTPMethodPOST, submitURL);
    if (req == INVALID_HANDLE) {
        PrintToServer("[InfDB] ERROR: Failed to create HTTP request");
        return Plugin_Handled;
    }
    SteamWorks_SetHTTPRequestRawPostBody(req, "application/json", json, strlen(json));
    SteamWorks_SetHTTPCallbacks(req, OnTestCompleted);
    SteamWorks_SendHTTPRequest(req);
    PrintToServer("[InfDB] Request sent, waiting for response...");
    return Plugin_Handled;
}

public void OnTestCompleted(Handle hRequest, bool bFailure, bool bRequestSuccessful, EHTTPStatusCode eStatusCode) {
    if (bFailure || !bRequestSuccessful) {
        PrintToServer("[InfDB] TEST FAILED: HTTP error, status %d", eStatusCode);
        CloseHandle(hRequest);
        return;
    }
    PrintToServer("[InfDB] TEST: Got HTTP %d", eStatusCode);
    SteamWorks_GetHTTPResponseBodyCallback(hRequest, OnTestBody);
    CloseHandle(hRequest);
}

public void OnTestBody(const char[] sBody) {
    PrintToServer("[InfDB] TEST response: %s", sBody);
}

public Action Cmd_InfDB(int client, int args) {
    if (!client) return Plugin_Handled;
    OpenMainMenu(client);
    return Plugin_Handled;
}

void OpenMainMenu(int client) {
    Menu menu = new Menu(MenuHandler_Main);
    menu.SetTitle("InfDB Settings");

    char buf[64];
    FormatEx(buf, sizeof(buf), "Show Mode: %s", g_iShowMode[client] == 0 ? "Always" : "Infinite Only");
    menu.AddItem("showmode", buf);

    FormatEx(buf, sizeof(buf), "Abbreviation: %s", ABBREV_NAMES[g_iAbbrev[client]]);
    menu.AddItem("abbrev", buf);

    menu.Display(client, MENU_TIME_FOREVER);
}

public int MenuHandler_Main(Menu menu, MenuAction action, int client, int item) {
    if (action == MenuAction_Select) {
        char info[32];
        menu.GetItem(item, info, sizeof(info));
        char buf[8];

        if (StrEqual(info, "showmode")) {
            g_iShowMode[client] = (g_iShowMode[client] + 1) % 2;
            IntToString(g_iShowMode[client], buf, sizeof(buf));
            SetClientCookie(client, g_hPrefShowMode, buf);
        } else if (StrEqual(info, "abbrev")) {
            g_iAbbrev[client] = (g_iAbbrev[client] + 1) % 3;
            IntToString(g_iAbbrev[client], buf, sizeof(buf));
            SetClientCookie(client, g_hPrefAbbrev, buf);
        }
        OpenMainMenu(client);
    } else if (action == MenuAction_End) {
        delete menu;
    }
    return 0;
}
