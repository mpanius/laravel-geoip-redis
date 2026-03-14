#!lua name=geoip

-- GeoIP country lookup via Redis Sorted Set
--
-- Sorted Set structure:
--   Key: geoip:v4
--   Score: end_ip (uint32 from CIDR)
--   Member: "CC:start_ip" (e.g. "RU:3232235520")
--
-- Algorithm:
--   ZRANGEBYSCORE key <ip_num> +inf LIMIT 0 1
--   → finds the first range where end_ip >= query_ip
--   → then verifies start_ip <= query_ip
--   → returns country code or false
--
-- Usage:
--   FCALL_RO geoip_country 1 geoip:v4 <ip_as_uint32>
--   → "RU" | false

redis.register_function{
  function_name = 'geoip_country',
  callback = function(keys, args)
    local ip_num = tonumber(args[1])
    if not ip_num then return false end

    local results = redis.call('ZRANGEBYSCORE', keys[1], ip_num, '+inf', 'LIMIT', '0', '1')
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
  end,
  flags = {'no-writes'}
}
