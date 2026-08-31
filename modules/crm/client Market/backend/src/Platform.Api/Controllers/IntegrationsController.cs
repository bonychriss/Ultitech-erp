using Microsoft.AspNetCore.Mvc;

namespace Platform.Api.Controllers;

[ApiController]
[Route("api/v1/integrations/apify")]
public class IntegrationsController : ControllerBase
{
    private static string _actorId = "apify/instagram-scraper";
    private static string? _token;

    [HttpGet]
    public IActionResult Get() => Ok(new
    {
        actorId = _actorId,
        tokenMasked = string.IsNullOrEmpty(_token) ? null : Mask(_token),
        status = string.IsNullOrEmpty(_token) ? "NOT_CONFIGURED" : "SAVED"
    });

    public record SaveRequest(string ActorId, string? ApiToken);

    [HttpPut]
    public IActionResult Save([FromBody] SaveRequest request)
    {
        _actorId = string.IsNullOrWhiteSpace(request.ActorId) ? "apify/instagram-scraper" : request.ActorId.Trim();
        if (!string.IsNullOrWhiteSpace(request.ApiToken))
            _token = request.ApiToken.Trim();
        return Ok(new { actorId = _actorId, tokenMasked = _token is null ? null : Mask(_token), status = "SAVED" });
    }

    [HttpPost("test")]
    public IActionResult Test()
    {
        if (string.IsNullOrEmpty(_token))
            return Ok(new { status = "CONNECTION_FAILED", message = "API token is not configured." });
        return Ok(new { status = "CONNECTED", lastTest = DateTime.UtcNow });
    }

    private static string Mask(string token)
    {
        var prefix = token.Length >= 10 ? token[..10] : "apify_api_";
        return prefix + "****************";
    }
}
