class AceError(Exception):
    def __init__(self, message: str, status: int = 400):
        super().__init__(message)
        self.message = message
        self.status = status


class NotFound(AceError):
    def __init__(self, message: str):
        super().__init__(message, 404)


class Conflict(AceError):
    def __init__(self, message: str):
        super().__init__(message, 409)


class MissingConfig(AceError):
    def __init__(self, message: str):
        super().__init__(message, 503)
