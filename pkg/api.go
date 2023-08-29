package barertc

import (
	"encoding/json"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"

	"git.kirsle.net/apps/barertc/pkg/config"
	"git.kirsle.net/apps/barertc/pkg/jwt"
	"git.kirsle.net/apps/barertc/pkg/log"
	"git.kirsle.net/apps/barertc/pkg/messages"
)

// Statistics (/api/statistics) returns info about the users currently logged onto the chat,
// for your website to call via CORS. The URL to your site needs to be in the CORSHosts array
// of your settings.toml.
func (s *Server) Statistics() http.HandlerFunc {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Handle the CORS header from your trusted domains.
		if origin := r.Header.Get("Origin"); origin != "" {
			var found bool
			for _, allowed := range config.Current.CORSHosts {
				if allowed == origin {
					found = true
				}
			}

			if found {
				w.Header().Set("Access-Control-Allow-Origin", origin)
			}
		}

		var result = struct {
			UserCount int
			Usernames []string
			Cameras   struct {
				Blue int
				Red  int
			}
		}{
			Usernames: []string{},
		}

		// Count all users + collect unique usernames.
		var unique = map[string]struct{}{}
		for _, sub := range s.IterSubscribers() {
			if sub.authenticated && sub.ChatStatus != "hidden" {
				result.UserCount++
				if _, ok := unique[sub.Username]; ok {
					continue
				}
				result.Usernames = append(result.Usernames, sub.Username)
				unique[sub.Username] = struct{}{}

				// Count cameras by color.
				if sub.VideoStatus&messages.VideoFlagActive == messages.VideoFlagActive {
					if sub.VideoStatus&messages.VideoFlagNSFW == messages.VideoFlagNSFW {
						result.Cameras.Red++
					} else {
						result.Cameras.Blue++
					}
				}
			}
		}

		w.Header().Set("Content-Type", "application/json")
		enc := json.NewEncoder(w)
		enc.SetIndent("", "  ")
		enc.Encode(result)
	})
}

// Authenticate (/api/authenticate) for the chatbot API.
//
// This endpoint will sign a JWT token using the claims you pass in. It requires
// the shared secret `AdminAPIKey` from your settings.toml and will sign the
// JWT claims you give it.
//
// It is a POST request with a json body containing the following schema:
//
//	{
//		"APIKey": "from settings.toml",
//		"Claims": {
//			"sub": "username",
//			"nick": "Display Name",
//			"op": false,
//			"img": "/static/photos/avatar.png",
//			"url": "/users/username",
//			"emoji": "🤖",
//			"gender": "m"
//		}
//	}
//
// The return schema looks like:
//
//	{
//		"OK": true,
//		"Error": "error string, omitted if none",
//		"JWT": "jwt token string"
//	}
func (s *Server) Authenticate() http.HandlerFunc {
	type request struct {
		APIKey string
		Claims jwt.Claims
	}

	type result struct {
		OK    bool
		Error string `json:",omitempty"`
		JWT   string `json:",omitempty"`
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// JSON writer for the response.
		w.Header().Set("Content-Type", "application/json")
		enc := json.NewEncoder(w)
		enc.SetIndent("", "  ")

		// Parse the request.
		if r.Method != http.MethodPost {
			w.WriteHeader(http.StatusBadRequest)
			enc.Encode(result{
				Error: "Only POST methods allowed",
			})
			return
		} else if r.Header.Get("Content-Type") != "application/json" {
			w.WriteHeader(http.StatusBadRequest)
			enc.Encode(result{
				Error: "Only application/json content-types allowed",
			})
			return
		}

		defer r.Body.Close()

		// Parse the request payload.
		var (
			params request
			dec    = json.NewDecoder(r.Body)
		)
		if err := dec.Decode(&params); err != nil {
			w.WriteHeader(http.StatusBadRequest)
			enc.Encode(result{
				Error: err.Error(),
			})
			return
		}

		// Validate the API key.
		if params.APIKey != config.Current.AdminAPIKey {
			w.WriteHeader(http.StatusUnauthorized)
			enc.Encode(result{
				Error: "Authentication denied.",
			})
			return
		}

		// Encode the JWT token.
		var claims = params.Claims
		token, err := claims.ReSign()
		if err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			enc.Encode(result{
				Error: "Error signing the JWT claims.",
			})
			return
		}

		enc.Encode(result{
			OK:  true,
			JWT: token,
		})
	})
}

// Shutdown (/api/shutdown) the chat server, hopefully to reboot it.
//
// This endpoint is equivalent to the operator '/shutdown' command but may be
// invoked by your website, or your chatbot. It requires the AdminAPIKey.
//
// It is a POST request with a json body containing the following schema:
//
//	{
//		"APIKey": "from settings.toml",
//	}
//
// The return schema looks like:
//
//	{
//		"OK": true,
//		"Error": "error string, omitted if none",
//	}
func (s *Server) ShutdownAPI() http.HandlerFunc {
	type request struct {
		APIKey string
		Claims jwt.Claims
	}

	type result struct {
		OK    bool
		Error string `json:",omitempty"`
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// JSON writer for the response.
		w.Header().Set("Content-Type", "application/json")
		enc := json.NewEncoder(w)
		enc.SetIndent("", "  ")

		// Parse the request.
		if r.Method != http.MethodPost {
			w.WriteHeader(http.StatusBadRequest)
			enc.Encode(result{
				Error: "Only POST methods allowed",
			})
			return
		} else if r.Header.Get("Content-Type") != "application/json" {
			w.WriteHeader(http.StatusBadRequest)
			enc.Encode(result{
				Error: "Only application/json content-types allowed",
			})
			return
		}

		defer r.Body.Close()

		// Parse the request payload.
		var (
			params request
			dec    = json.NewDecoder(r.Body)
		)
		if err := dec.Decode(&params); err != nil {
			w.WriteHeader(http.StatusBadRequest)
			enc.Encode(result{
				Error: err.Error(),
			})
			return
		}

		// Validate the API key.
		if params.APIKey != config.Current.AdminAPIKey {
			w.WriteHeader(http.StatusUnauthorized)
			enc.Encode(result{
				Error: "Authentication denied.",
			})
			return
		}

		// Send the response.
		enc.Encode(result{
			OK: true,
		})

		// Defer a shutdown a moment later.
		go func() {
			time.Sleep(2 * time.Second)
			os.Exit(1)
		}()

		// Attempt to broadcast, but if deadlocked this might not go out.
		go func() {
			s.Broadcast(messages.Message{
				Action:   messages.ActionError,
				Username: "ChatServer",
				Message:  "The chat server is going down for a reboot NOW!",
			})
		}()
	})
}

// BlockList (/api/blocklist) allows your website to pre-sync mute lists between your
// user accounts, so that when they see each other in chat they will pre-emptively mute
// or boot one another.
//
// It is a POST request with a json body containing the following schema:
//
//	{
//		"APIKey": "from settings.toml",
//		"Username": "soandso",
//		"Blocklist": [ "list", "of", "other", "usernames" ],
//	}
//
// The chat server will remember these mappings (until rebooted). How they are
// used is that the blocklist is embedded in the front-end page when the username
// signs in later. As part of the On Connect handler, the front-end will send the
// list of usernames in a bulk `mute` command to the server. This way even if the
// chat server reboots while the user is connected, when it comes back up and the user
// reconnects they will retransmit their block list.
func (s *Server) BlockList() http.HandlerFunc {
	type request struct {
		APIKey    string
		Username  string
		Blocklist []string
	}

	type result struct {
		OK    bool
		Error string `json:",omitempty"`
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// JSON writer for the response.
		w.Header().Set("Content-Type", "application/json")
		enc := json.NewEncoder(w)
		enc.SetIndent("", "  ")

		// Parse the request.
		if r.Method != http.MethodPost {
			w.WriteHeader(http.StatusBadRequest)
			enc.Encode(result{
				Error: "Only POST methods allowed",
			})
			return
		} else if r.Header.Get("Content-Type") != "application/json" {
			w.WriteHeader(http.StatusBadRequest)
			enc.Encode(result{
				Error: "Only application/json content-types allowed",
			})
			return
		}

		defer r.Body.Close()

		// Parse the request payload.
		var (
			params request
			dec    = json.NewDecoder(r.Body)
		)
		if err := dec.Decode(&params); err != nil {
			w.WriteHeader(http.StatusBadRequest)
			enc.Encode(result{
				Error: err.Error(),
			})
			return
		}

		// Validate the API key.
		if params.APIKey != config.Current.AdminAPIKey {
			w.WriteHeader(http.StatusUnauthorized)
			enc.Encode(result{
				Error: "Authentication denied.",
			})
			return
		}

		// Store the cached blocklist.
		SetCachedBlocklist(params.Username, params.Blocklist)
		enc.Encode(result{
			OK: true,
		})
	})
}

// Blocklist cache sent over from your website.
var (
	// Map of username to the list of usernames they block.
	cachedBlocklist   map[string][]string
	cachedBlocklistMu sync.RWMutex
)

func init() {
	cachedBlocklist = map[string][]string{}
}

// GetCachedBlocklist returns the blocklist for a username.
func GetCachedBlocklist(username string) []string {
	cachedBlocklistMu.RLock()
	defer cachedBlocklistMu.RUnlock()
	if list, ok := cachedBlocklist[username]; ok {
		log.Debug("GetCachedBlocklist(%s) blocks %s", username, list)
		return list
	}
	log.Debug("GetCachedBlocklist(%s): no blocklist stored", username)
	return []string{}
}

// SetCachedBlocklist sets the blocklist cache for a user.
func SetCachedBlocklist(username string, blocklist []string) {
	log.Info("SetCachedBlocklist: %s mutes users %s", username, strings.Join(blocklist, ", "))
	cachedBlocklistMu.Lock()
	defer cachedBlocklistMu.Unlock()
	cachedBlocklist[username] = blocklist
}
