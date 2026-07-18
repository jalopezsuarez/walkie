#!/usr/bin/env bash
# End-to-end API smoke test. Requires the local test server on :8080.
set -uo pipefail
BASE=http://127.0.0.1:8080/api
pass=0; fail=0
chk() { # chk "desc" expected_substr actual
  if [[ "$3" == *"$2"* ]]; then echo "  ok  - $1"; pass=$((pass+1));
  else echo "  FAIL- $1"; echo "        expected ~ '$2'"; echo "        got        '$3'"; fail=$((fail+1)); fi
}
j() { python3 -c "import sys,json;print(json.load(sys.stdin).get('$1',''))"; }

echo "# auth flow (Alice)"
R=$(curl -s -X POST $BASE/auth/request-code -H 'Content-Type: application/json' -d '{"email":"alice@example.com"}')
CODE=$(echo "$R" | j debug_code); chk "request-code returns debug code" "" "$(echo -n "$CODE" | tr -d '\n')"
[ -n "$CODE" ] && chk "code is 6 digits" "6" "${#CODE}"
R=$(curl -s -X POST $BASE/auth/verify -H 'Content-Type: application/json' -d "{\"email\":\"alice@example.com\",\"code\":\"$CODE\"}")
ATOK=$(echo "$R" | j token); chk "verify returns token" "" "$(echo -n "$ATOK" | head -c1)"

echo "# wrong code rejected"
curl -s -X POST $BASE/auth/request-code -H 'Content-Type: application/json' -d '{"email":"bob@example.com"}' >/dev/null
R=$(curl -s -X POST $BASE/auth/verify -H 'Content-Type: application/json' -d '{"email":"bob@example.com","code":"000000"}')
chk "wrong code -> code_invalid" "code_invalid" "$R"

echo "# auth flow (Bob)"
R=$(curl -s -X POST $BASE/auth/request-code -H 'Content-Type: application/json' -d '{"email":"bob@example.com"}')
CODE=$(echo "$R" | j debug_code)
R=$(curl -s -X POST $BASE/auth/verify -H 'Content-Type: application/json' -d "{\"email\":\"bob@example.com\",\"code\":\"$CODE\"}")
BTOK=$(echo "$R" | j token); chk "bob token" "" "$(echo -n "$BTOK" | head -c1)"

echo "# me + settings"
R=$(curl -s $BASE/me -H "Authorization: Bearer $ATOK"); chk "me returns email" "alice@example.com" "$R"
R=$(curl -s -X PATCH $BASE/me -H "Authorization: Bearer $ATOK" -H 'Content-Type: application/json' -d '{"display_name":"Alice A"}')
chk "update name" "Alice A" "$R"
R=$(curl -s $BASE/me -H "Authorization: Bearer wrongtoken"); chk "bad token -> 401" "unauthorized" "$R"

echo "# pairing via QR"
R=$(curl -s -X POST $BASE/link/qr -H "Authorization: Bearer $ATOK")
PTOK=$(echo "$R" | j token); chk "qr token issued" "" "$(echo -n "$PTOK" | head -c1)"
chk "qr svg present" "<svg" "$R"
R=$(curl -s -X POST $BASE/link/claim -H "Authorization: Bearer $ATOK" -H 'Content-Type: application/json' -d "{\"token\":\"$PTOK\"}")
chk "self-pair rejected" "self_pair" "$R"
# Bob claims Alice's QR
R=$(curl -s -X POST $BASE/link/qr -H "Authorization: Bearer $ATOK")
PTOK=$(echo "$R" | j token)
R=$(curl -s -X POST $BASE/link/claim -H "Authorization: Bearer $BTOK" -H 'Content-Type: application/json' -d "{\"token\":\"$PTOK\"}")
LINK=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['link']['link_id'])" 2>/dev/null)
chk "bob linked to alice" "$LINK" "$LINK"
R=$(curl -s -X POST $BASE/link/claim -H "Authorization: Bearer $BTOK" -H 'Content-Type: application/json' -d "{\"token\":\"$PTOK\"}")
chk "reused token rejected" "pairing_invalid" "$R"

echo "# both see the link"
R=$(curl -s $BASE/links -H "Authorization: Bearer $ATOK"); chk "alice sees bob" "\"link_id\"" "$R"
R=$(curl -s $BASE/links -H "Authorization: Bearer $BTOK"); chk "bob sees alice" "\"link_id\"" "$R"
ALINK=$(curl -s $BASE/links -H "Authorization: Bearer $ATOK" | python3 -c "import sys,json;print(json.load(sys.stdin)['links'][0]['link_id'])")

echo "# text message alice -> bob"
R=$(curl -s -X POST $BASE/links/$ALINK/messages -H "Authorization: Bearer $ATOK" -H 'Content-Type: application/json' -d '{"type":"text","text":"hola bob"}')
MID=$(echo "$R" | j id); chk "text sent" "text" "$R"
R=$(curl -s $BASE/links/$LINK/messages -H "Authorization: Bearer $BTOK"); chk "bob reads text" "hola bob" "$R"
chk "message decrypted correctly" "\"mine\":false" "$R"

echo "# audio message bob -> alice"
AUDIO=$(python3 -c "import base64;print(base64.b64encode(b'FAKEAUDIOxxxxxxxxxxxx').decode())")
R=$(curl -s -X POST $BASE/links/$LINK/messages -H "Authorization: Bearer $BTOK" -H 'Content-Type: application/json' -d "{\"type\":\"audio\",\"audio\":\"$AUDIO\",\"mime\":\"audio/webm\",\"duration_ms\":1500}")
chk "audio sent" "audio" "$R"
R=$(curl -s $BASE/links/$ALINK/messages -H "Authorization: Bearer $ATOK")
chk "alice gets audio b64" "$AUDIO" "$R"
chk "alice gets duration" "1500" "$R"

echo "# sender can delete own message"
R=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE $BASE/links/$ALINK/messages/$MID -H "Authorization: Bearer $ATOK")
chk "delete own msg -> 204" "204" "$R"
R=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE $BASE/links/$ALINK/messages/99999 -H "Authorization: Bearer $BTOK")
chk "delete other's msg -> 404" "404" "$R"

echo "# access control: stranger cannot read conversation"
curl -s -X POST $BASE/auth/request-code -H 'Content-Type: application/json' -d '{"email":"eve@example.com"}' >/dev/null
CODE=$(curl -s -X POST $BASE/auth/request-code -H 'Content-Type: application/json' -d '{"email":"eve@example.com"}' | j debug_code)
ETOK=$(curl -s -X POST $BASE/auth/verify -H 'Content-Type: application/json' -d "{\"email\":\"eve@example.com\",\"code\":\"$CODE\"}" | j token)
R=$(curl -s -o /dev/null -w "%{http_code}" $BASE/links/$LINK/messages -H "Authorization: Bearer $ETOK")
chk "eve blocked from conversation -> 404" "404" "$R"

echo "# unlink deletes for both"
R=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE $BASE/links/$ALINK -H "Authorization: Bearer $ATOK")
chk "alice unlinks -> 204" "204" "$R"
R=$(curl -s $BASE/links -H "Authorization: Bearer $BTOK")
chk "bob no longer sees alice" "\"links\":[]" "$R"

echo
echo "RESULT: $pass passed, $fail failed"
exit $fail
