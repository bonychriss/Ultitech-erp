using Microsoft.AspNetCore.Mvc;

namespace Platform.Api.Controllers;

[ApiController]
[Route("api/v1/auth")]
public class AuthController : ControllerBase
{
    public record LoginRequest(string Email, string Password);

    [HttpPost("login")]
    public IActionResult Login([FromBody] LoginRequest request)
    {
        if (string.IsNullOrWhiteSpace(request.Email) || string.IsNullOrWhiteSpace(request.Password))
            return BadRequest(new { message = "Invalid credentials." });

        return Ok(new
        {
            accessToken = "dev-mock-token",
            refreshToken = "dev-mock-refresh",
            user = new { email = request.Email, role = "SuperAdministrator" }
        });
    }

    [HttpPost("logout")]
    public IActionResult Logout() => Ok();
}
