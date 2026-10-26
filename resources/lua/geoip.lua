#!lua name=geoip

-- GeoIP country lookup via Redis Sorted Sets (IPv4 + IPv6)
--
-- The function name and signature are the same for both families; the family is
-- told by the shape of ARGV[1], the caller passes the matching key in KEYS[1].
--
-- IPv4  (key geoip:v4)
--   Sorted Set: score = end_ip (uint32), member = "CC:start_ip" (e.g. "RU:3232235520")
--   ARGV[1]   = ip as decimal uint32
--   ZRANGEBYSCORE key <ip> +inf LIMIT 0 1  -> first range with end_ip >= ip, then start_ip <= ip
--
-- IPv6  (key geoip:v6)
--   128-bit values do not fit a double score (53 bits), so the whole Sorted Set has
--   score 0 and ordering comes from the member itself (lexicographic):
--   member  = "<end hex32>:<start hex32>:CC"   (lowercase hex, fixed width)
--   ARGV[1] = ip as 32 hex chars
--   ZRANGEBYLEX key [<ip hex> + LIMIT 0 1  -> first range with end >= ip, then start <= ip
--
-- Returns the country code or false.
--
-- Usage:
--   FCALL_RO geoip_country 1 geoip:v4 134744072
--   FCALL_RO geoip_country 1 geoip:v6 20010db8000000000000000000000001

-- a <= b for two 32-char hex strings, in 8-char chunks (exact in a double): strcoll-independent
local function hex_le(a, b)
  for i = 1, 25, 8 do
    local x = tonumber(string.sub(a, i, i + 7), 16)
    local y = tonumber(string.sub(b, i, i + 7), 16)
    if x ~= y then return x < y end
  end
  return true
end

local function lookup_v4(key, ip_num)
  local results = redis.call('ZRANGEBYSCORE', key, ip_num, '+inf', 'LIMIT', '0', '1')
  if #results == 0 then return false end

  local entry = results[1]
  local sep = string.find(entry, ':')
  if not sep then return false end

  local cc = string.sub(entry, 1, sep - 1)
  local start_ip = tonumber(string.sub(entry, sep + 1))

  if ip_num >= start_ip then
    return cc
  end

  return false
end

local function lookup_v6(key, ip_hex)
  local results = redis.call('ZRANGEBYLEX', key, '[' .. ip_hex, '+', 'LIMIT', '0', '1')
  if #results == 0 then return false end

  -- "<end 32>:<start 32>:CC"
  local entry = results[1]
  if #entry < 67 then return false end

  if hex_le(string.sub(entry, 34, 65), ip_hex) then
    return string.sub(entry, 67)
  end

  return false
end

redis.register_function{
  function_name = 'geoip_country',
  callback = function(keys, args)
    local arg = args[1]
    if not arg then return false end

    if #arg == 32 then
      arg = string.lower(arg)
      if not string.find(arg, '^%x+$') then return false end
      return lookup_v6(keys[1], arg)
    end

    local ip_num = tonumber(arg)
    if not ip_num then return false end

    return lookup_v4(keys[1], ip_num)
  end,
  flags = {'no-writes'}
}
